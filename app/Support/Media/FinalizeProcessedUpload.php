<?php

namespace App\Support\Media;

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaPathException;
use App\Support\Media\Exceptions\UploadProcessingException;
use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\SecurityAudit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class FinalizeProcessedUpload
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private TusTransportClient $transportClient,
        private FfprobeMediaValidator $validator,
        private TransitionUploadStatus $transitionUploadStatus,
        private UploadConfiguration $configuration,
    ) {}

    public function process(Upload $upload): void
    {
        $upload->refresh();

        if ($upload->status === UploadStatus::Completed) {
            $this->cleanupTusSidecar($upload);

            return;
        }

        if ($upload->status !== UploadStatus::Processing) {
            return;
        }

        $claim = $upload->processing_claim;

        if ($claim === null) {
            $claim = $this->validateAndClaim($upload);
            $upload->refresh();
        }

        $this->assertClaim($claim);

        try {
            Cache::lock(
                'upload-admission:ordinary',
                $this->configuration->processingJobTimeoutSeconds + 60,
            )->block(10, function () use ($upload, $claim): void {
                $this->promoteAndCommit($upload->refresh(), $claim);
            });
        } catch (LockTimeoutException $exception) {
            throw UploadProcessingException::transient(
                'media_finalization_busy',
                'Media finalization is temporarily busy and will be retried.',
                $exception,
            );
        }

        $this->cleanupTusSidecar($upload->refresh());
    }

    /** @return array<string, mixed> */
    private function validateAndClaim(Upload $upload): array
    {
        $this->assertCompletedTransport($upload);
        [$disk, $stagePath] = $this->guardedStage($upload);
        [$targetPath, $targetDirectory] = $this->guardedTarget($disk, $upload);

        if (! $this->filesystem->isRegularFile($stagePath)
            || $this->filesystem->fileSize($stagePath) !== $upload->declared_size
        ) {
            throw UploadProcessingException::permanent(
                'staged_file_invalid',
                'The staged file is missing, unsafe, or has the wrong size.',
            );
        }

        $deviceId = $this->filesystem->deviceId($stagePath);
        $inodeId = $this->filesystem->inodeId($stagePath);

        if ($deviceId === null
            || $inodeId === null
            || $deviceId !== $this->filesystem->deviceId($disk->root)
        ) {
            throw UploadProcessingException::permanent(
                'media_mount_changed',
                'The media disk identity changed during validation.',
            );
        }

        $replacement = null;

        if ($upload->replaces_media_file_id === null) {
            $this->assertOrdinaryDatabasePreconditions($upload);

            if ($this->filesystem->pathExists($targetDirectory) || $this->filesystem->pathExists($targetPath)) {
                throw UploadProcessingException::permanent(
                    'target_path_conflict',
                    'The final movie directory or file already exists.',
                );
            }
        } else {
            [$oldMediaFile, $oldDisk, $oldPath] = $this->replacementRecord($upload);
            $this->assertReplacementDatabasePreconditions($upload, $oldMediaFile);
            $this->assertOldFile($oldPath, $oldMediaFile, null);

            $samePath = $oldDisk->id === $disk->id
                && $oldMediaFile->relative_path === $upload->target_relative_path;
            $sameDirectory = $oldDisk->id === $disk->id
                && dirname($oldMediaFile->relative_path) === dirname($upload->target_relative_path);

            if (! $samePath && $this->filesystem->pathExists($targetPath)) {
                throw UploadProcessingException::permanent(
                    'target_path_conflict',
                    'The replacement target file already exists.',
                );
            }

            if (! $sameDirectory && $this->filesystem->pathExists($targetDirectory)) {
                throw UploadProcessingException::permanent(
                    'target_directory_conflict',
                    'The replacement target directory already exists.',
                );
            }

            $oldDeviceId = $this->filesystem->deviceId($oldPath);
            $oldInodeId = $this->filesystem->inodeId($oldPath);

            if ($oldDeviceId === null || $oldInodeId === null) {
                throw UploadProcessingException::permanent(
                    'replacement_primary_invalid',
                    'The tracked current primary is physically inconsistent.',
                );
            }

            $replacement = [
                'media_file_id' => $oldMediaFile->getKey(),
                'source_upload_id' => $oldMediaFile->source_upload_id,
                'disk_id' => $oldMediaFile->disk_id,
                'relative_path' => $oldMediaFile->relative_path,
                'size_bytes' => $oldMediaFile->size_bytes,
                'device_id' => $oldDeviceId,
                'inode_id' => $oldInodeId,
                'mode' => $samePath ? 'atomic_same_path_swap' : 'finalize_then_delete',
            ];
        }

        $this->validateTusSidecar($upload, $stagePath);
        $probe = $this->validator->probe($stagePath);
        $claim = [
            'version' => $replacement === null ? 1 : 2,
            'expected_size' => $upload->declared_size,
            'device_id' => $deviceId,
            ...($replacement === null ? [] : ['inode_id' => $inodeId, 'replacement' => $replacement]),
            ...$probe,
        ];

        return DB::transaction(function () use ($upload, $claim): array {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUpload->status !== UploadStatus::Processing) {
                throw UploadProcessingException::permanent(
                    'upload_state_conflict',
                    'The upload state changed during validation.',
                );
            }

            if ($lockedUpload->processing_claim !== null) {
                return $lockedUpload->processing_claim;
            }

            if ($lockedUpload->declared_size !== $claim['expected_size']
                || $lockedUpload->confirmed_offset !== $claim['expected_size']
                || ($lockedUpload->replaces_media_file_id === null) !== ($claim['version'] === 1)
            ) {
                throw UploadProcessingException::permanent(
                    'upload_size_mismatch',
                    'The upload admission state changed during validation.',
                );
            }

            $lockedUpload->update([
                'processing_claim' => $claim,
                'finalization_started_at' => now(),
            ]);

            return $claim;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $claim */
    private function promoteAndCommit(Upload $upload, array $claim): void
    {
        if ($upload->status === UploadStatus::Completed) {
            return;
        }

        if ($upload->status !== UploadStatus::Processing || $upload->processing_claim === null) {
            throw UploadProcessingException::permanent(
                'finalization_claim_missing',
                'A persisted validation claim is required before final placement.',
            );
        }

        if (Arr::get($claim, 'version') === 2) {
            $this->finalizeReplacement($upload, $claim);

            return;
        }

        $this->finalizeOrdinaryUpload($upload, $claim);
    }

    /** @param array<string, mixed> $claim */
    private function finalizeOrdinaryUpload(Upload $upload, array $claim): void
    {
        [$disk, $stagePath] = $this->guardedStage($upload);
        [$targetPath, $targetDirectory] = $this->guardedTarget($disk, $upload);

        if ($this->filesystem->deviceId($disk->root) !== $claim['device_id']) {
            throw UploadProcessingException::permanent(
                'media_mount_changed',
                'The media disk identity changed before final placement.',
            );
        }

        $this->assertOrdinaryDatabasePreconditions($upload);
        $stageExists = $this->filesystem->pathExists($stagePath);
        $targetExists = $this->filesystem->pathExists($targetPath);

        if ($stageExists) {
            $this->assertNewFile($stagePath, $claim, false, 'staged_file_invalid');
        }

        if ($targetExists) {
            $this->assertNewFile($targetPath, $claim, false, 'target_file_conflict');

            if ($stageExists && ! $this->filesystem->sameInode($stagePath, $targetPath)) {
                throw UploadProcessingException::permanent(
                    'target_file_conflict',
                    'A different file already exists at the final media path.',
                );
            }
        }

        if (! $stageExists && ! $targetExists) {
            throw UploadProcessingException::permanent(
                'media_bytes_missing',
                'Neither the validated stage nor its claimed final target exists.',
            );
        }

        if ($stageExists && ! $targetExists) {
            $createdDirectory = $this->prepareOrdinaryTargetDirectory($targetDirectory);

            if (! $this->filesystem->createHardLinkExclusively($stagePath, $targetPath)) {
                $targetExists = $this->filesystem->pathExists($targetPath);

                if ($targetExists && ! $this->filesystem->sameInode($stagePath, $targetPath)) {
                    if ($createdDirectory) {
                        $this->filesystem->removeDirectoryIfEmpty($targetDirectory);
                    }

                    throw UploadProcessingException::permanent(
                        'target_file_conflict',
                        'A different file appeared at the final media path.',
                    );
                }

                if (! $targetExists) {
                    if ($createdDirectory) {
                        $this->filesystem->removeDirectoryIfEmpty($targetDirectory);
                    }

                    throw UploadProcessingException::transient(
                        'media_promotion_unavailable',
                        'The filesystem could not provide safe exclusive final placement.',
                    );
                }
            }

            $this->assertNewFile($targetPath, $claim, false, 'target_file_conflict');

            if (! $this->filesystem->sameInode($stagePath, $targetPath)) {
                throw UploadProcessingException::permanent(
                    'target_file_conflict',
                    'The final media path does not reference the validated staged file.',
                );
            }
        }

        if ($this->filesystem->pathExists($stagePath) && ! $this->filesystem->deleteFile($stagePath)) {
            throw UploadProcessingException::transient(
                'staging_unlink_failed',
                'The validated stage could not be released after final placement.',
            );
        }

        $this->assertNewFile($targetPath, $claim, false, 'target_file_conflict');
        $this->commitMediaFile($upload, $claim);
    }

    /** @param array<string, mixed> $claim */
    private function finalizeReplacement(Upload $upload, array $claim): void
    {
        [$disk, $stagePath] = $this->guardedStage($upload);
        [$targetPath, $targetDirectory] = $this->guardedTarget($disk, $upload);
        [$oldMediaFile, $oldDisk, $oldPath] = $this->replacementRecord($upload);
        $oldDirectory = dirname($oldPath);
        $replacement = $this->replacementClaim($claim);

        $this->assertReplacementClaimMatches($upload, $oldMediaFile, $replacement);
        $this->assertReplacementDatabasePreconditions($upload, $oldMediaFile);

        if ($this->filesystem->deviceId($disk->root) !== $claim['device_id']
            || $this->filesystem->deviceId($oldDisk->root) !== $replacement['device_id']
        ) {
            throw UploadProcessingException::permanent(
                'media_mount_changed',
                'A media disk identity changed before replacement.',
            );
        }

        if ($replacement['mode'] === 'atomic_same_path_swap') {
            $this->performAtomicSamePathReplacement($stagePath, $targetPath, $oldMediaFile, $claim);
        } else {
            $this->performFinalizeThenDeleteReplacement(
                $stagePath,
                $targetPath,
                $targetDirectory,
                $oldPath,
                $oldDirectory,
                $oldMediaFile,
                $claim,
            );
        }

        $mediaFile = $this->commitMediaFile($upload, $claim);

        if ($mediaFile !== null) {
            SecurityAudit::mediaReplacementCompleted($upload, $mediaFile);
        }
    }

    /** @param array<string, mixed> $claim */
    private function performAtomicSamePathReplacement(
        string $stagePath,
        string $targetPath,
        MediaFile $oldMediaFile,
        array $claim,
    ): void {
        $stageExists = $this->filesystem->pathExists($stagePath);
        $targetIsNew = $this->isClaimedNewFile($targetPath, $claim);

        if (! $stageExists && $targetIsNew) {
            return;
        }

        if (! $stageExists || $targetIsNew) {
            throw UploadProcessingException::permanent(
                'replacement_state_ambiguous',
                'The atomic replacement state is inconsistent with its persisted claim.',
            );
        }

        $this->assertNewFile($stagePath, $claim, true, 'staged_file_invalid');
        $this->assertOldFile($targetPath, $oldMediaFile, $this->replacementClaim($claim));

        if (! $this->filesystem->replaceFileAtomically($stagePath, $targetPath)) {
            throw UploadProcessingException::transient(
                'replacement_swap_failed',
                'The validated file could not atomically replace the tracked primary.',
            );
        }

        $this->assertNewFile($targetPath, $claim, true, 'replacement_target_invalid');
    }

    /** @param array<string, mixed> $claim */
    private function performFinalizeThenDeleteReplacement(
        string $stagePath,
        string $targetPath,
        string $targetDirectory,
        string $oldPath,
        string $oldDirectory,
        MediaFile $oldMediaFile,
        array $claim,
    ): void {
        $stageExists = $this->filesystem->pathExists($stagePath);
        $targetExists = $this->filesystem->pathExists($targetPath);

        if ($stageExists) {
            $this->assertNewFile($stagePath, $claim, true, 'staged_file_invalid');
        }

        if ($targetExists) {
            $this->assertNewFile($targetPath, $claim, true, 'replacement_target_invalid');

            if ($stageExists && ! $this->filesystem->sameInode($stagePath, $targetPath)) {
                throw UploadProcessingException::permanent(
                    'replacement_target_conflict',
                    'A different file exists at the replacement target.',
                );
            }
        }

        if (! $stageExists && ! $targetExists) {
            throw UploadProcessingException::permanent(
                'media_bytes_missing',
                'The claimed replacement bytes are missing.',
            );
        }

        if ($stageExists && ! $targetExists) {
            $this->prepareReplacementTargetDirectory($targetDirectory, $oldDirectory);

            if (! $this->filesystem->createHardLinkExclusively($stagePath, $targetPath)) {
                if (! $this->filesystem->pathExists($targetPath)
                    || ! $this->filesystem->sameInode($stagePath, $targetPath)
                ) {
                    throw UploadProcessingException::transient(
                        'media_promotion_unavailable',
                        'The replacement target could not be created exclusively.',
                    );
                }
            }

            $this->assertNewFile($targetPath, $claim, true, 'replacement_target_invalid');
        }

        if ($this->filesystem->pathExists($stagePath) && ! $this->filesystem->deleteFile($stagePath)) {
            throw UploadProcessingException::transient(
                'staging_unlink_failed',
                'The validated stage could not be released after final placement.',
            );
        }

        $this->assertNewFile($targetPath, $claim, true, 'replacement_target_invalid');

        if ($this->filesystem->pathExists($oldPath)) {
            $this->assertOldFile($oldPath, $oldMediaFile, $this->replacementClaim($claim));

            if (! $this->filesystem->deleteFile($oldPath)) {
                throw UploadProcessingException::transient(
                    'replacement_delete_failed',
                    'The exact tracked primary could not be removed after final placement.',
                );
            }
        } elseif (! $this->isClaimedNewFile($targetPath, $claim)) {
            throw UploadProcessingException::permanent(
                'replacement_state_ambiguous',
                'An absent old primary requires the exact claimed replacement inode.',
            );
        }

        if ($oldDirectory !== $targetDirectory) {
            $this->filesystem->removeDirectoryIfEmpty($oldDirectory);
        }
    }

    private function prepareOrdinaryTargetDirectory(string $targetDirectory): bool
    {
        if ($this->filesystem->pathExists($targetDirectory)) {
            if ($this->filesystem->isSymbolicLink($targetDirectory)
                || ! $this->filesystem->isDirectory($targetDirectory)
                || ! $this->filesystem->isDirectoryEmpty($targetDirectory)
            ) {
                throw UploadProcessingException::permanent(
                    'target_directory_conflict',
                    'The final movie directory is no longer empty and exclusive.',
                );
            }

            return false;
        }

        if (! $this->filesystem->createDirectory($targetDirectory)) {
            throw UploadProcessingException::transient(
                'target_directory_unavailable',
                'The final movie directory could not be created.',
            );
        }

        return true;
    }

    private function prepareReplacementTargetDirectory(string $targetDirectory, string $oldDirectory): void
    {
        if ($targetDirectory === $oldDirectory) {
            if ($this->filesystem->isSymbolicLink($targetDirectory)
                || ! $this->filesystem->isDirectory($targetDirectory)
            ) {
                throw UploadProcessingException::permanent(
                    'target_directory_conflict',
                    'The replacement directory is unsafe.',
                );
            }

            return;
        }

        if ($this->filesystem->pathExists($targetDirectory)) {
            if ($this->filesystem->isSymbolicLink($targetDirectory)
                || ! $this->filesystem->isDirectory($targetDirectory)
                || ! $this->filesystem->isDirectoryEmpty($targetDirectory)
            ) {
                throw UploadProcessingException::permanent(
                    'target_directory_conflict',
                    'The replacement target directory is no longer empty and exclusive.',
                );
            }

            return;
        }

        if (! $this->filesystem->createDirectory($targetDirectory)) {
            throw UploadProcessingException::transient(
                'target_directory_unavailable',
                'The replacement target directory could not be created.',
            );
        }
    }

    /** @param array<string, mixed> $claim */
    private function commitMediaFile(Upload $upload, array $claim): ?MediaFile
    {
        return DB::transaction(function () use ($upload, $claim): ?MediaFile {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUpload->status === UploadStatus::Completed) {
                return null;
            }

            if ($lockedUpload->status !== UploadStatus::Processing
                || $lockedUpload->processing_claim !== $claim
                || $lockedUpload->finalization_started_at === null
            ) {
                throw UploadProcessingException::permanent(
                    'finalization_claim_conflict',
                    'The persisted finalization claim is inconsistent.',
                );
            }

            $oldMediaFile = null;

            if ($lockedUpload->replaces_media_file_id === null) {
                $this->assertOrdinaryDatabasePreconditions($lockedUpload);
            } else {
                $oldMediaFile = MediaFile::query()
                    ->whereKey($lockedUpload->replaces_media_file_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertReplacementDatabasePreconditions($lockedUpload, $oldMediaFile);
                $oldMediaFile->update(['active_path_key' => null]);
            }

            $mediaFile = MediaFile::query()->create([
                'media_item_id' => $lockedUpload->media_item_id,
                'source_upload_id' => $lockedUpload->getKey(),
                'disk_id' => $lockedUpload->disk_id,
                'relative_path' => $lockedUpload->target_relative_path,
                'active_path_key' => MediaFile::activePathKey(
                    $lockedUpload->disk_id,
                    $lockedUpload->target_relative_path,
                ),
                'size_bytes' => $claim['expected_size'],
                'container' => $claim['container'],
                'duration_milliseconds' => $claim['duration_milliseconds'],
                'video_metadata' => $claim['video'],
                'audio_metadata' => $claim['audio'],
                'probe_snapshot' => $claim['snapshot'],
                'finalized_at' => now(),
            ]);

            $mediaItem = MediaItem::query()
                ->whereKey($lockedUpload->media_item_id)
                ->lockForUpdate()
                ->firstOrFail();
            $mediaItem->update(['current_media_file_id' => $mediaFile->getKey()]);

            if ($oldMediaFile !== null) {
                $oldMediaFile->update([
                    'replaced_by_media_file_id' => $mediaFile->getKey(),
                    'replaced_at' => now(),
                    'removed_at' => now(),
                    'removal_reason' => 'replaced_without_backup',
                ]);
            }

            $lockedUpload->update(['expires_at' => null]);
            $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Completed);

            return $mediaFile;
        }, attempts: 3);
    }

    private function assertOrdinaryDatabasePreconditions(Upload $upload): void
    {
        if (MediaFile::query()->where('source_upload_id', $upload->getKey())->exists()) {
            throw UploadProcessingException::permanent(
                'media_database_conflict',
                'A contradictory media-file record already exists for this upload.',
            );
        }

        if (MediaFile::query()
            ->where('active_path_key', MediaFile::activePathKey($upload->disk_id, $upload->target_relative_path))
            ->exists()
        ) {
            throw UploadProcessingException::permanent(
                'media_database_conflict',
                'The final media path is already tracked by another active record.',
            );
        }

        if (MediaItem::query()->whereKey($upload->media_item_id)->whereNotNull('current_media_file_id')->exists()) {
            throw UploadProcessingException::permanent(
                'media_database_conflict',
                'The movie already has a current primary file.',
            );
        }
    }

    private function assertReplacementDatabasePreconditions(Upload $upload, MediaFile $oldMediaFile): void
    {
        if ($upload->replacement_confirmed_at === null
            || $upload->replaces_media_file_id !== $oldMediaFile->getKey()
            || $oldMediaFile->media_item_id !== $upload->media_item_id
            || $oldMediaFile->replaced_at !== null
            || $oldMediaFile->removed_at !== null
            || $oldMediaFile->replaced_by_media_file_id !== null
            || $oldMediaFile->active_path_key !== MediaFile::activePathKey($oldMediaFile->disk_id, $oldMediaFile->relative_path)
        ) {
            throw UploadProcessingException::permanent(
                'replacement_database_conflict',
                'The tracked replacement primary is no longer active and exact.',
            );
        }

        if (! MediaItem::query()
            ->whereKey($upload->media_item_id)
            ->where('current_media_file_id', $oldMediaFile->getKey())
            ->exists()
        ) {
            throw UploadProcessingException::permanent(
                'replacement_database_conflict',
                'The replacement target is no longer the current primary.',
            );
        }

        if (MediaFile::query()
            ->where('active_path_key', MediaFile::activePathKey($upload->disk_id, $upload->target_relative_path))
            ->whereKeyNot($oldMediaFile->getKey())
            ->exists()
            || MediaFile::query()->where('source_upload_id', $upload->getKey())->exists()
        ) {
            throw UploadProcessingException::permanent(
                'replacement_database_conflict',
                'Another active media record conflicts with the replacement.',
            );
        }
    }

    /** @return array{MediaFile, ConfiguredMediaDisk, string} */
    private function replacementRecord(Upload $upload): array
    {
        $oldMediaFile = MediaFile::query()
            ->with('sourceUpload')
            ->find($upload->replaces_media_file_id);
        $actor = $upload->user()->first();
        $sourceUpload = $oldMediaFile?->sourceUpload;

        if ($oldMediaFile === null
            || $actor === null
            || $sourceUpload === null
            || $sourceUpload->status !== UploadStatus::Completed
            || ($sourceUpload->user_id !== $actor->getKey() && ! $actor->isAdministrator())
            || $sourceUpload->media_item_id !== $upload->media_item_id
            || $sourceUpload->disk_id !== $oldMediaFile->disk_id
            || $sourceUpload->target_relative_path !== $oldMediaFile->relative_path
            || $sourceUpload->declared_size !== $oldMediaFile->size_bytes
            || $sourceUpload->confirmed_offset !== $oldMediaFile->size_bytes
        ) {
            throw UploadProcessingException::permanent(
                'replacement_primary_invalid',
                'The tracked current primary metadata is inconsistent.',
            );
        }

        $oldDisk = $this->guardedDisk($oldMediaFile->disk_id);

        try {
            $oldPath = $this->pathGuard->resolveChild($oldDisk->root, $oldMediaFile->relative_path);
        } catch (MediaPathException $exception) {
            throw UploadProcessingException::permanent(
                'replacement_path_unsafe',
                'The tracked current-primary path is unsafe.',
                $exception,
            );
        }

        return [$oldMediaFile, $oldDisk, $oldPath];
    }

    /** @param array<string, mixed> $replacement */
    private function assertReplacementClaimMatches(
        Upload $upload,
        MediaFile $oldMediaFile,
        array $replacement,
    ): void {
        if ($replacement['media_file_id'] !== $oldMediaFile->getKey()
            || $replacement['source_upload_id'] !== $oldMediaFile->source_upload_id
            || $replacement['disk_id'] !== $oldMediaFile->disk_id
            || $replacement['relative_path'] !== $oldMediaFile->relative_path
            || $replacement['size_bytes'] !== $oldMediaFile->size_bytes
            || $upload->replaces_media_file_id !== $oldMediaFile->getKey()
        ) {
            throw UploadProcessingException::permanent(
                'replacement_claim_conflict',
                'The persisted replacement claim no longer matches the tracked primary.',
            );
        }
    }

    /** @param array<string, mixed>|null $replacement */
    private function assertOldFile(string $path, MediaFile $oldMediaFile, ?array $replacement): void
    {
        if ($this->filesystem->isSymbolicLink($path)
            || ! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $oldMediaFile->size_bytes
            || ($replacement !== null && (
                $this->filesystem->deviceId($path) !== $replacement['device_id']
                || $this->filesystem->inodeId($path) !== $replacement['inode_id']
            ))
        ) {
            throw UploadProcessingException::permanent(
                'replacement_primary_invalid',
                'The exact tracked current-primary file changed before replacement.',
            );
        }
    }

    /** @param array<string, mixed> $claim */
    private function assertNewFile(string $path, array $claim, bool $requireInode, string $errorCode): void
    {
        if ($this->filesystem->isSymbolicLink($path)
            || ! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $claim['expected_size']
            || $this->filesystem->deviceId($path) !== $claim['device_id']
            || ($requireInode && $this->filesystem->inodeId($path) !== $claim['inode_id'])
        ) {
            throw UploadProcessingException::permanent(
                $errorCode,
                'The media file at the finalization boundary is unsafe or inconsistent.',
            );
        }
    }

    /** @param array<string, mixed> $claim */
    private function isClaimedNewFile(string $path, array $claim): bool
    {
        return $this->filesystem->pathExists($path)
            && ! $this->filesystem->isSymbolicLink($path)
            && $this->filesystem->isRegularFile($path)
            && $this->filesystem->fileSize($path) === $claim['expected_size']
            && $this->filesystem->deviceId($path) === $claim['device_id']
            && $this->filesystem->inodeId($path) === $claim['inode_id'];
    }

    /**
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function replacementClaim(array $claim): array
    {
        $replacement = Arr::get($claim, 'replacement');

        if (! is_array($replacement)) {
            throw UploadProcessingException::permanent(
                'finalization_claim_invalid',
                'The persisted replacement claim is invalid.',
            );
        }

        $normalizedReplacement = [];

        foreach ($replacement as $key => $value) {
            if (! is_string($key)) {
                throw UploadProcessingException::permanent(
                    'finalization_claim_invalid',
                    'The persisted replacement claim is invalid.',
                );
            }

            $normalizedReplacement[$key] = $value;
        }

        return $normalizedReplacement;
    }

    private function assertCompletedTransport(Upload $upload): void
    {
        if ($upload->declared_size !== $upload->confirmed_offset) {
            throw UploadProcessingException::permanent(
                'upload_size_mismatch',
                'The declared, received, and staged byte counts do not agree.',
            );
        }

        if ($upload->tus_resource_id !== $upload->uuid) {
            throw UploadProcessingException::permanent(
                'tus_identity_mismatch',
                'The completed tus resource identity is inconsistent.',
            );
        }

        try {
            $remote = $this->transportClient->head($upload);
        } catch (UploadTransportException $exception) {
            if ($exception->errorCode === 'upload_transport_unavailable') {
                throw UploadProcessingException::transient(
                    'tus_verification_unavailable',
                    'The completed upload transport state is temporarily unavailable.',
                    $exception,
                );
            }

            throw UploadProcessingException::permanent(
                'tus_state_invalid',
                'The completed tus resource state is invalid.',
                $exception,
            );
        }

        if ($remote === null
            || $remote['length'] !== $upload->declared_size
            || $remote['offset'] !== $upload->declared_size
        ) {
            throw UploadProcessingException::permanent(
                'tus_size_mismatch',
                'The declared, received, and staged byte counts do not agree.',
            );
        }
    }

    /** @return array{ConfiguredMediaDisk, string} */
    private function guardedStage(Upload $upload): array
    {
        $disk = $this->guardedDisk($upload->disk_id);

        try {
            return [$disk, $this->pathGuard->resolveChild($disk->root, $upload->staging_relative_path)];
        } catch (MediaPathException $exception) {
            throw UploadProcessingException::permanent(
                'staging_path_unsafe',
                'The staged media path is unsafe.',
                $exception,
            );
        }
    }

    private function guardedDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->find($diskId);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw UploadProcessingException::transient(
                'media_disk_unavailable',
                'A required media disk is temporarily unavailable.',
            );
        }

        return $disk;
    }

    /** @return array{string, string} */
    private function guardedTarget(ConfiguredMediaDisk $disk, Upload $upload): array
    {
        try {
            return [
                $this->pathGuard->resolveChild($disk->root, $upload->target_relative_path),
                $this->pathGuard->resolveChild($disk->root, dirname($upload->target_relative_path)),
            ];
        } catch (MediaPathException $exception) {
            throw UploadProcessingException::permanent(
                'finalization_path_unsafe',
                'The final media path is unsafe.',
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $claim */
    private function assertClaim(array $claim): void
    {
        $version = Arr::get($claim, 'version');
        $expectedSize = Arr::get($claim, 'expected_size');
        $video = Arr::get($claim, 'video');
        $replacement = Arr::get($claim, 'replacement');
        $validReplacement = $version === 1 || ($version === 2
            && is_int(Arr::get($claim, 'inode_id'))
            && is_array($replacement)
            && is_int(Arr::get($replacement, 'media_file_id'))
            && is_int(Arr::get($replacement, 'source_upload_id'))
            && is_string(Arr::get($replacement, 'disk_id'))
            && is_string(Arr::get($replacement, 'relative_path'))
            && is_int(Arr::get($replacement, 'size_bytes'))
            && is_int(Arr::get($replacement, 'device_id'))
            && is_int(Arr::get($replacement, 'inode_id'))
            && in_array(Arr::get($replacement, 'mode'), ['atomic_same_path_swap', 'finalize_then_delete'], true));

        if (! $validReplacement
            || ! is_int($expectedSize)
            || $expectedSize < 1
            || ! is_int(Arr::get($claim, 'device_id'))
            || ! is_string(Arr::get($claim, 'container'))
            || ! is_int(Arr::get($claim, 'duration_milliseconds'))
            || ! is_array($video)
            || $video === []
            || ! is_array(Arr::get($claim, 'audio'))
            || ! is_array(Arr::get($claim, 'snapshot'))
        ) {
            throw UploadProcessingException::permanent(
                'finalization_claim_invalid',
                'The persisted media validation claim is invalid.',
            );
        }
    }

    private function cleanupTusSidecar(Upload $upload): void
    {
        if ($upload->status !== UploadStatus::Completed) {
            return;
        }

        $sidecarPath = $this->configuration->tusMetadataPath.'/'.$upload->uuid.'.info';

        if (! $this->filesystem->pathExists($sidecarPath)) {
            return;
        }

        if ($this->filesystem->isSymbolicLink($sidecarPath)
            || ! $this->filesystem->isRegularFile($sidecarPath)
            || ! $this->filesystem->deleteFile($sidecarPath)
        ) {
            throw UploadProcessingException::transient(
                'tus_sidecar_cleanup_failed',
                'The completed tus metadata sidecar could not be cleaned up safely.',
            );
        }
    }

    private function validateTusSidecar(Upload $upload, string $stagePath): void
    {
        $sidecarPath = $this->configuration->tusMetadataPath.'/'.$upload->uuid.'.info';
        $contents = $this->filesystem->readFile($sidecarPath);

        if ($contents === null || $contents === '' || strlen($contents) > 65_536) {
            throw UploadProcessingException::permanent(
                'tus_metadata_missing',
                'The completed tus metadata sidecar is missing or invalid.',
            );
        }

        try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw UploadProcessingException::permanent(
                'tus_metadata_invalid',
                'The completed tus metadata sidecar is invalid.',
                $exception,
            );
        }

        $storage = is_array($metadata) ? ($metadata['Storage'] ?? null) : null;
        $storagePath = is_array($storage) ? ($storage['Path'] ?? null) : null;

        if (! is_array($metadata)
            || array_is_list($metadata)
            || ($metadata['ID'] ?? null) !== $upload->uuid
            || ($metadata['Size'] ?? null) !== $upload->declared_size
            || ($metadata['SizeIsDeferred'] ?? null) !== false
            || ($metadata['MetaData'] ?? null) !== ['upload_uuid' => $upload->uuid]
            || ($metadata['IsPartial'] ?? null) !== false
            || ($metadata['IsFinal'] ?? null) !== false
            || ! in_array($metadata['PartialUploads'] ?? null, [null, []], true)
            || ! is_string($storagePath)
            || ! hash_equals($stagePath, $storagePath)
        ) {
            throw UploadProcessingException::permanent(
                'tus_metadata_invalid',
                'The completed tus metadata sidecar is inconsistent.',
            );
        }
    }
}
