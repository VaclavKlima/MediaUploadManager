<?php

namespace App\Support\Media;

use App\Actions\CleanupResolvedLibraryFindingFolder;
use App\Actions\CreateOrReplayUploadReservation;
use App\Actions\CreateOrReuseMediaItem;
use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\HardLinkCreationException;
use App\Support\Media\Exceptions\RelocationVerificationException;
use App\Support\SecurityAudit;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class LibraryImportProcessor
{
    private const LOCK_SECONDS = 240;

    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private JellyfinMoviePathBuilder $pathBuilder,
        private FfprobeMediaValidator $validator,
        private CreateOrReuseMediaItem $createOrReuseMediaItem,
        private CacheManager $cacheManager,
        private CleanupResolvedLibraryFindingFolder $cleanupResolvedFolder,
        private LibraryRelocationVerifier $relocationVerifier,
    ) {}

    public function process(LibraryFinding $finding, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException('Only an administrator may import discovered files.');
        }

        $repository = $this->cacheManager->store('database');

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw new RuntimeException('Library import locking is unavailable.');
        }

        $repository->getStore()
            ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, self::LOCK_SECONDS)
            ->block(10, function () use ($finding, $actor): void {
                $finding = $finding->refresh();

                if (in_array($finding->resolution, ['imported', 'relocated'], true)) {
                    $this->cleanupResolvedFolder->execute($finding, $actor);

                    return;
                }

                $claimType = $finding->operation_claim['type'] ?? null;
                $isRestore = $claimType === 'restore'
                    || $finding->paired_missing_finding_id !== null
                    || in_array($finding->status, ['restore_ready', 'restore_queued', 'restoring'], true);

                if ($isRestore) {
                    $claim = $finding->operation_claim ?? $this->validateAndClaimRestore($finding, $actor);
                    $this->moveAndCommitRestore($finding->refresh(), $actor, $claim);

                    return;
                }

                $claim = $finding->operation_claim ?? $this->validateAndClaim($finding, $actor);
                $this->moveAndCommit($finding->refresh(), $actor, $claim);
            });
    }

    /** @return array<string, mixed> */
    private function validateAndClaim(LibraryFinding $finding, User $actor): array
    {
        if ($finding->kind !== 'discovered' || ! in_array($finding->status, ['ready', 'failed', 'import_queued'], true)) {
            throw new RuntimeException('This finding is not ready to import.');
        }

        $snapshot = $this->identitySnapshot($finding);

        if ($snapshot['tmdb_id'] !== $finding->tmdb_id
            || ($snapshot['imdb_id'] !== null && $snapshot['imdb_id'] !== $finding->imdb_id)
        ) {
            throw new RuntimeException('The finding identity no longer matches its confirmed TMDB snapshot.');
        }

        $disk = $this->healthyDisk($finding->disk_id);
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);
        $this->assertSnapshot($sourcePath, $finding->size_bytes, $finding->device_id, $finding->inode_id);

        if ($this->filesystem->deviceId($disk->root) !== $finding->device_id) {
            throw new RuntimeException('The source file is no longer on its configured disk filesystem.');
        }

        if (Upload::query()
            ->where('disk_id', $finding->disk_id)
            ->where(function ($query) use ($finding): void {
                $query->where('target_relative_path', $finding->relative_path)
                    ->orWhere('staging_relative_path', $finding->relative_path);
            })
            ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
            ->exists()
        ) {
            throw new RuntimeException('The source file is claimed by an upload.');
        }
        $destination = $this->pathBuilder->build(new MediaItem($snapshot), $finding->source_filename);
        $mediaItem = $this->createOrReuseMediaItem->handle($snapshot);

        if ($finding->destination_relative_path !== null
            && $finding->destination_relative_path !== $destination->relativePath
        ) {
            throw new RuntimeException('The canonical destination changed after confirmation.');
        }

        $this->assertDatabaseAvailability($mediaItem);
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $destination->relativePath);
        $destinationDirectory = dirname($destinationPath);

        if ($finding->relative_path !== $destination->relativePath && $this->filesystem->pathExists($destinationPath)) {
            throw new RuntimeException('The canonical destination is already occupied.');
        }

        $this->assertNoOtherVideoInDestination($destinationDirectory, $sourcePath, $destinationPath);

        $probe = $this->validator->probe($sourcePath);
        $this->assertSnapshot($sourcePath, $finding->size_bytes, $finding->device_id, $finding->inode_id);
        $claim = [
            'version' => 1,
            'type' => 'import',
            'actor_id' => $actor->id,
            'media_item_id' => $mediaItem->id,
            'disk_id' => $disk->id,
            'source_relative_path' => $finding->relative_path,
            'destination_relative_path' => $destination->relativePath,
            'size_bytes' => $finding->size_bytes,
            'device_id' => $finding->device_id,
            'inode_id' => $finding->inode_id,
            'probe' => $probe,
        ];

        DB::transaction(function () use ($finding, $claim, $mediaItem, $destination): void {
            $locked = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($locked->operation_claim !== null) {
                return;
            }

            if (! in_array($locked->status, ['ready', 'failed', 'import_queued'], true)
                || $locked->size_bytes !== $claim['size_bytes']
                || $locked->device_id !== $claim['device_id']
                || $locked->inode_id !== $claim['inode_id']
            ) {
                throw new RuntimeException('The scan finding changed before import.');
            }

            $locked->update([
                'media_item_id' => $mediaItem->id,
                'destination_relative_path' => $destination->relativePath,
                'operation_claim' => $claim,
                'status' => 'importing',
                'error_detail' => null,
            ]);
        }, attempts: 3);

        SecurityAudit::libraryImportConfirmed($finding, $actor, $destination->relativePath);

        return $claim;
    }

    /** @param array<string, mixed> $claim */
    private function moveAndCommit(LibraryFinding $finding, User $actor, array $claim): void
    {
        $this->assertClaim($finding, $actor, $claim);
        $disk = $this->healthyDisk($finding->disk_id);
        $sourceRelativePath = $this->claimPath($claim, 'source_relative_path');
        $destinationRelativePath = $this->claimPath($claim, 'destination_relative_path');
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $sourceRelativePath);
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $destinationRelativePath);
        $destinationDirectory = dirname($destinationPath);
        $sourceExists = $this->filesystem->pathExists($sourcePath);
        $destinationExists = $this->filesystem->pathExists($destinationPath);

        if ($sourceExists) {
            $this->assertSnapshot($sourcePath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        }

        if ($destinationExists) {
            $this->assertSnapshot($destinationPath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        }

        if (! $sourceExists && ! $destinationExists) {
            throw new RuntimeException('The claimed movie bytes are missing.');
        }

        if ($sourcePath !== $destinationPath) {
            if ($sourceExists && ! $destinationExists) {
                if ($this->filesystem->pathExists($destinationDirectory)) {
                    if (! $this->filesystem->isDirectory($destinationDirectory)
                        || $this->filesystem->isSymbolicLink($destinationDirectory)
                    ) {
                        throw new RuntimeException('The canonical destination directory is unsafe.');
                    }
                } elseif (! $this->filesystem->createDirectory($destinationDirectory)) {
                    throw new RuntimeException('The canonical destination directory could not be created.');
                }

                $this->createHardLinkExclusively($sourcePath, $destinationPath);
                $destinationExists = true;
            }

            if ($sourceExists && ! $this->filesystem->sameInode($sourcePath, $destinationPath)) {
                throw new RuntimeException('Source and destination do not reference the same file.');
            }

            if ($sourceExists && ! $this->filesystem->deleteFile($sourcePath)) {
                throw new RuntimeException('The source path could not be released after linking.');
            }
        }

        $this->assertSnapshot($destinationPath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        $mediaFile = DB::transaction(function () use ($finding, $actor, $claim): MediaFile {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->resolution === 'imported' && $lockedFinding->media_file_id !== null) {
                return MediaFile::query()->findOrFail($lockedFinding->media_file_id);
            }

            $mediaItem = MediaItem::query()->whereKey($claim['media_item_id'])->lockForUpdate()->firstOrFail();
            $this->assertDatabaseAvailability($mediaItem);
            $probe = $claim['probe'];

            if (! is_array($probe)) {
                throw new RuntimeException('The import probe claim is invalid.');
            }

            $mediaFile = MediaFile::query()->create([
                'media_item_id' => $mediaItem->id,
                'source_upload_id' => null,
                'imported_by_user_id' => $actor->id,
                'import_provenance' => [
                    'type' => 'recursive_library_import',
                    'library_scan_id' => $lockedFinding->library_scan_id,
                    'library_finding_id' => $lockedFinding->id,
                    'source_relative_path' => $claim['source_relative_path'],
                    'relocation_proof' => [
                        'type' => 'inode',
                        'size_bytes' => $claim['size_bytes'],
                        'device_id' => $claim['device_id'],
                        'inode_id' => $claim['inode_id'],
                    ],
                ],
                'disk_id' => $claim['disk_id'],
                'relative_path' => $claim['destination_relative_path'],
                'size_bytes' => $claim['size_bytes'],
                'container' => $probe['container'],
                'duration_milliseconds' => $probe['duration_milliseconds'],
                'video_metadata' => $probe['video'],
                'audio_metadata' => $probe['audio'],
                'probe_snapshot' => $probe['snapshot'],
                'finalized_at' => now(),
            ]);
            $mediaItem->update(['current_media_file_id' => $mediaFile->id]);
            $lockedFinding->update([
                'media_file_id' => $mediaFile->id,
                'status' => 'resolved',
                'resolution' => 'imported',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);

            return $mediaFile;
        }, attempts: 3);

        SecurityAudit::libraryImportCompleted($finding, $mediaFile, $actor);
        $this->cleanupResolvedFolder->execute($finding->refresh(), $actor);
    }

    /** @return array<string, mixed> */
    private function validateAndClaimRestore(LibraryFinding $finding, User $actor): array
    {
        if ($finding->kind !== 'discovered'
            || $finding->paired_missing_finding_id === null
            || ! in_array($finding->status, ['restore_ready', 'restore_queued', 'failed'], true)
            || ! is_int($finding->tmdb_id)
            || $finding->destination_relative_path === null
        ) {
            throw new RuntimeException('This finding is not ready to restore.');
        }

        $missing = LibraryFinding::query()->findOrFail($finding->paired_missing_finding_id);

        try {
            $proof = $this->relocationVerifier->prove($finding, $missing, $finding->tmdb_id);
        } catch (RelocationVerificationException $exception) {
            if ($exception->reason === 'old_path_returned') {
                $this->resolveReturnedOldPath($finding, $missing);
            }

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $snapshot = $this->identitySnapshot($finding);
        $destination = $this->pathBuilder->build(new MediaItem($snapshot), $finding->source_filename);

        if ($destination->relativePath !== $finding->destination_relative_path) {
            throw new RuntimeException('The canonical destination changed after relocation verification.');
        }

        $disk = $this->healthyDisk($finding->disk_id);
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $destination->relativePath);

        if ($sourcePath !== $destinationPath && $this->filesystem->pathExists($destinationPath)) {
            throw new RuntimeException('The canonical destination is already occupied.');
        }

        $this->assertNoOtherVideoInDestination(dirname($destinationPath), $sourcePath, $destinationPath);

        if (MediaFile::query()
            ->where('active_path_key', MediaFile::activePathKey($disk->id, $destination->relativePath))
            ->whereKeyNot($missing->media_file_id)
            ->exists()
        ) {
            throw new RuntimeException('Another active media record occupies the canonical destination.');
        }

        $claim = [
            'version' => 1,
            'type' => 'restore',
            'actor_id' => $actor->id,
            'media_item_id' => $missing->media_item_id,
            'old_media_file_id' => $missing->media_file_id,
            'missing_finding_id' => $missing->id,
            'disk_id' => $disk->id,
            'source_relative_path' => $finding->relative_path,
            'destination_relative_path' => $destination->relativePath,
            'tracked_disk_id' => $missing->disk_id,
            'tracked_relative_path' => $missing->relative_path,
            'size_bytes' => $finding->size_bytes,
            'device_id' => $finding->device_id,
            'inode_id' => $finding->inode_id,
            'proof' => $proof,
        ];

        DB::transaction(function () use ($finding, $missing, $claim): void {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();
            $lockedMissing = LibraryFinding::query()->whereKey($missing)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->operation_claim !== null) {
                return;
            }

            if ($lockedFinding->paired_missing_finding_id !== $lockedMissing->id
                || $lockedMissing->resolved_at !== null
                || $lockedMissing->status !== 'missing'
                || ! in_array($lockedFinding->status, ['restore_ready', 'restore_queued', 'failed'], true)
                || $lockedFinding->size_bytes !== $claim['size_bytes']
                || $lockedFinding->device_id !== $claim['device_id']
                || $lockedFinding->inode_id !== $claim['inode_id']
            ) {
                throw new RuntimeException('The relocation pair changed before it could be claimed.');
            }

            $lockedFinding->update([
                'operation_claim' => $claim,
                'status' => 'restoring',
                'error_detail' => null,
            ]);
        }, attempts: 3);

        SecurityAudit::libraryRelocationConfirmed($finding, $missing, $actor, $destination->relativePath);

        return $claim;
    }

    /** @param array<string, mixed> $claim */
    private function moveAndCommitRestore(LibraryFinding $finding, User $actor, array $claim): void
    {
        $this->assertRestoreClaim($finding, $actor, $claim);
        $disk = $this->healthyDisk($finding->disk_id);
        $trackedDisk = $this->healthyDisk($this->claimPath($claim, 'tracked_disk_id'));
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $this->claimPath($claim, 'source_relative_path'));
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $this->claimPath($claim, 'destination_relative_path'));
        $trackedPath = $this->pathGuard->resolveChild($trackedDisk->root, $this->claimPath($claim, 'tracked_relative_path'));
        $sourceExists = $this->filesystem->pathExists($sourcePath);
        $destinationExists = $this->filesystem->pathExists($destinationPath);

        if ($this->filesystem->pathExists($trackedPath)) {
            throw new RuntimeException('The tracked path returned after the relocation was claimed.');
        }

        if ($sourceExists) {
            $this->assertSnapshot($sourcePath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        }

        if ($destinationExists) {
            $this->assertSnapshot($destinationPath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        }

        if (! $sourceExists && ! $destinationExists) {
            throw new RuntimeException('The claimed relocated movie bytes are missing.');
        }

        if ($sourcePath !== $destinationPath) {
            if ($sourceExists && ! $destinationExists) {
                $destinationDirectory = dirname($destinationPath);

                if ($this->filesystem->pathExists($destinationDirectory)) {
                    if (! $this->filesystem->isDirectory($destinationDirectory)
                        || $this->filesystem->isSymbolicLink($destinationDirectory)
                    ) {
                        throw new RuntimeException('The canonical destination directory is unsafe.');
                    }
                } elseif (! $this->filesystem->createDirectory($destinationDirectory)) {
                    throw new RuntimeException('The canonical destination directory could not be created.');
                }

                $this->createHardLinkExclusively($sourcePath, $destinationPath);
                $destinationExists = true;
            }

            if ($sourceExists && ! $this->filesystem->sameInode($sourcePath, $destinationPath)) {
                throw new RuntimeException('The relocation source and destination do not reference the same file.');
            }

            if ($sourceExists && ! $this->filesystem->deleteFile($sourcePath)) {
                throw new RuntimeException('The found path could not be released after linking.');
            }
        }

        $this->assertSnapshot($destinationPath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        $proof = $this->stringKeyedArray($claim['proof']);

        $this->relocationVerifier->assertProof($destinationPath, $proof);
        $mediaFile = DB::transaction(function () use ($finding, $actor, $claim, $proof): MediaFile {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->resolution === 'relocated'
                && $lockedFinding->media_file_id !== null
                && $lockedFinding->media_file_id !== $claim['old_media_file_id']
            ) {
                return MediaFile::query()->findOrFail($lockedFinding->media_file_id);
            }

            $lockedMissing = LibraryFinding::query()->whereKey($claim['missing_finding_id'])->lockForUpdate()->firstOrFail();
            $mediaItem = MediaItem::query()->whereKey($claim['media_item_id'])->lockForUpdate()->firstOrFail();
            $oldMediaFile = MediaFile::query()->whereKey($claim['old_media_file_id'])->lockForUpdate()->firstOrFail();

            if (! CanonicalJson::equivalent($lockedFinding->operation_claim, $claim)
                || $lockedFinding->paired_missing_finding_id !== $lockedMissing->id
                || $lockedMissing->resolved_at !== null
                || $lockedMissing->media_file_id !== $oldMediaFile->id
                || $mediaItem->current_media_file_id !== $oldMediaFile->id
                || $oldMediaFile->media_item_id !== $mediaItem->id
                || $oldMediaFile->active_path_key !== MediaFile::activePathKey($oldMediaFile->disk_id, $oldMediaFile->relative_path)
                || $oldMediaFile->removed_at !== null
            ) {
                throw new RuntimeException('The relocation database state changed before commit.');
            }

            if (MediaFile::query()
                ->where('media_item_id', $mediaItem->id)
                ->whereNotNull('active_path_key')
                ->whereKeyNot($oldMediaFile->id)
                ->exists()
            ) {
                throw new RuntimeException('Another active media file conflicts with this relocation.');
            }

            if (LibraryFinding::query()
                ->where('library_scan_id', $lockedFinding->library_scan_id)
                ->whereKeyNot($lockedFinding->id)
                ->where('kind', 'discovered')
                ->where('tmdb_id', $lockedFinding->tmdb_id)
                ->whereNull('resolved_at')
                ->exists()
            ) {
                throw new RuntimeException('Another discovered file now identifies the same movie.');
            }

            $sourceUploadId = is_int($proof['source_upload_id'] ?? null)
                ? $proof['source_upload_id']
                : null;

            if (Upload::query()
                ->where('media_item_id', $mediaItem->id)
                ->when($sourceUploadId !== null, fn ($query) => $query->whereKeyNot($sourceUploadId))
                ->whereIn('status', [
                    UploadStatus::Pending->value,
                    UploadStatus::Uploading->value,
                    UploadStatus::Paused->value,
                    UploadStatus::Processing->value,
                    UploadStatus::Failed->value,
                ])
                ->exists()
            ) {
                throw new RuntimeException('Another upload became active before relocation commit.');
            }

            $oldMediaFile->update(['removed_at' => now(), 'removal_reason' => 'relocated']);
            $mediaFile = MediaFile::query()->create([
                'media_item_id' => $mediaItem->id,
                'source_upload_id' => null,
                'imported_by_user_id' => $actor->id,
                'import_provenance' => [
                    'type' => 'library_relocation_restore',
                    'previous_media_file_id' => $oldMediaFile->id,
                    'library_scan_id' => $lockedFinding->library_scan_id,
                    'library_finding_id' => $lockedFinding->id,
                    'missing_finding_id' => $lockedMissing->id,
                    'source_relative_path' => $claim['source_relative_path'],
                    'relocation_proof' => $proof,
                ],
                'disk_id' => $claim['disk_id'],
                'relative_path' => $claim['destination_relative_path'],
                'size_bytes' => $oldMediaFile->size_bytes,
                'container' => $oldMediaFile->container,
                'duration_milliseconds' => $oldMediaFile->duration_milliseconds,
                'video_metadata' => $oldMediaFile->video_metadata,
                'audio_metadata' => $oldMediaFile->audio_metadata,
                'probe_snapshot' => $oldMediaFile->probe_snapshot,
                'finalized_at' => now(),
            ]);
            $mediaItem->update(['current_media_file_id' => $mediaFile->id]);
            $lockedFinding->update([
                'media_file_id' => $mediaFile->id,
                'status' => 'resolved',
                'resolution' => 'relocated',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);
            $lockedMissing->update([
                'status' => 'resolved',
                'resolution' => 'relocated',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);

            return $mediaFile;
        }, attempts: 3);

        SecurityAudit::libraryRelocationCompleted($finding, $mediaFile, $actor);
        $this->cleanupResolvedFolder->execute($finding->refresh(), $actor);
    }

    private function resolveReturnedOldPath(LibraryFinding $finding, LibraryFinding $missing): void
    {
        DB::transaction(function () use ($finding, $missing): void {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();
            $lockedMissing = LibraryFinding::query()->whereKey($missing)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->operation_claim !== null || $lockedMissing->resolved_at !== null) {
                return;
            }

            $lockedMissing->update([
                'status' => 'resolved',
                'resolution' => 'restored',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);
            $lockedFinding->update([
                'paired_missing_finding_id' => null,
                'status' => 'conflict',
                'error_detail' => 'The tracked path returned; this discovered file is now a normal conflict.',
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $claim */
    private function assertRestoreClaim(LibraryFinding $finding, User $actor, array $claim): void
    {
        if (($claim['version'] ?? null) !== 1
            || ($claim['type'] ?? null) !== 'restore'
            || ($claim['actor_id'] ?? null) !== $actor->id
            || ($claim['disk_id'] ?? null) !== $finding->disk_id
            || ($claim['source_relative_path'] ?? null) !== $finding->relative_path
            || ($claim['missing_finding_id'] ?? null) !== $finding->paired_missing_finding_id
            || ($claim['size_bytes'] ?? null) !== $finding->size_bytes
            || ($claim['device_id'] ?? null) !== $finding->device_id
            || ($claim['inode_id'] ?? null) !== $finding->inode_id
            || ! is_array($claim['proof'] ?? null)
        ) {
            throw new RuntimeException('The persisted restore claim is invalid.');
        }
    }

    private function healthyDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->find($diskId);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw new RuntimeException('The source disk is unavailable or its marker identity changed.');
        }

        return $disk;
    }

    private function createHardLinkExclusively(string $sourcePath, string $destinationPath): void
    {
        try {
            $created = $this->filesystem->createHardLinkExclusively($sourcePath, $destinationPath);
        } catch (HardLinkCreationException $exception) {
            throw new RuntimeException(
                "Hard-link creation was denied by the media filesystem. Set MEDIA_GID to the source file's numeric group ID, recreate the media services, and retry the import.",
                previous: $exception,
            );
        }

        if (! $created) {
            throw new RuntimeException('The canonical destination could not be reserved exclusively.');
        }
    }

    /**
     * @return array{
     *     tmdb_id: int,
     *     imdb_id: string|null,
     *     title: string,
     *     original_title: string|null,
     *     release_date: string|null,
     *     release_year: int|null,
     *     overview: string|null,
     *     poster_path: string|null,
     *     original_language: string|null,
     *     metadata_version: int,
     *     metadata_snapshot: array<string, mixed>
     * }
     */
    private function identitySnapshot(LibraryFinding $finding): array
    {
        $snapshot = $finding->identity_snapshot;

        if ($snapshot === null
            || ! is_int($snapshot['tmdb_id'] ?? null)
            || ! is_string($snapshot['title'] ?? null)
            || ! is_int($snapshot['metadata_version'] ?? null)
            || ! is_array($snapshot['metadata_snapshot'] ?? null)
        ) {
            throw new RuntimeException('Identify this file before importing it.');
        }

        return [
            'tmdb_id' => $snapshot['tmdb_id'],
            'imdb_id' => $this->nullableString($snapshot, 'imdb_id'),
            'title' => $snapshot['title'],
            'original_title' => $this->nullableString($snapshot, 'original_title'),
            'release_date' => $this->nullableString($snapshot, 'release_date'),
            'release_year' => $this->nullableInteger($snapshot, 'release_year'),
            'overview' => $this->nullableString($snapshot, 'overview'),
            'poster_path' => $this->nullableString($snapshot, 'poster_path'),
            'original_language' => $this->nullableString($snapshot, 'original_language'),
            'metadata_version' => $snapshot['metadata_version'],
            'metadata_snapshot' => $this->stringKeyedArray($snapshot['metadata_snapshot']),
        ];
    }

    /** @param array<string, mixed> $values */
    private function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new RuntimeException('The confirmed identity snapshot is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function nullableInteger(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;

        if ($value !== null && ! is_int($value)) {
            throw new RuntimeException('The confirmed identity snapshot is invalid.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $values): array
    {
        if (! is_array($values)) {
            throw new RuntimeException('The confirmed identity snapshot is invalid.');
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('The confirmed identity snapshot is invalid.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $claim */
    private function claimPath(array $claim, string $key): string
    {
        $path = $claim[$key] ?? null;

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The persisted import claim is invalid.');
        }

        return $path;
    }

    private function assertSnapshot(string $path, mixed $size, mixed $device, mixed $inode): void
    {
        if (! is_int($size) || ! is_int($device) || ! is_int($inode)
            || ! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $size
            || $this->filesystem->deviceId($path) !== $device
            || $this->filesystem->inodeId($path) !== $inode
        ) {
            throw new RuntimeException('The file no longer matches its verified scan snapshot.');
        }
    }

    private function assertDatabaseAvailability(MediaItem $mediaItem): void
    {
        if ($mediaItem->current_media_file_id !== null
            || MediaFile::query()->where('media_item_id', $mediaItem->id)->whereNotNull('active_path_key')->exists()
            || Upload::query()->where('media_item_id', $mediaItem->id)
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists()
        ) {
            throw new RuntimeException('This movie already has a current file or active upload.');
        }
    }

    private function assertNoOtherVideoInDestination(string $directory, string $sourcePath, string $destinationPath): void
    {
        if (! $this->filesystem->pathExists($directory)) {
            return;
        }

        if (! $this->filesystem->isDirectory($directory) || $this->filesystem->isSymbolicLink($directory)) {
            throw new RuntimeException('The canonical destination directory is unsafe.');
        }

        $entries = @scandir($directory);

        if (! is_array($entries)) {
            throw new RuntimeException('The canonical destination directory cannot be inspected.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if ($path === $sourcePath || $path === $destinationPath) {
                continue;
            }

            if ($this->filesystem->isSymbolicLink($path)) {
                throw new RuntimeException('The canonical destination directory contains a symbolic link.');
            }

            if ($this->filesystem->isRegularFile($path)
                && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)
            ) {
                throw new RuntimeException('The canonical destination already contains another movie version.');
            }
        }
    }

    /** @param array<string, mixed> $claim */
    private function assertClaim(LibraryFinding $finding, User $actor, array $claim): void
    {
        $claimType = $claim['type'] ?? null;

        if (($claim['version'] ?? null) !== 1
            || ! in_array($claimType, [null, 'import'], true)
            || ($claim['actor_id'] ?? null) !== $actor->id
            || ($claim['disk_id'] ?? null) !== $finding->disk_id
            || ($claim['source_relative_path'] ?? null) !== $finding->relative_path
            || ($claim['size_bytes'] ?? null) !== $finding->size_bytes
            || ($claim['device_id'] ?? null) !== $finding->device_id
            || ($claim['inode_id'] ?? null) !== $finding->inode_id
        ) {
            throw new RuntimeException('The persisted import claim is invalid.');
        }
    }
}
