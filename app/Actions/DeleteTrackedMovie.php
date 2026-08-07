<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Exceptions\MovieDeletionException;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaPathException;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\TrackedMovieDeletionClaim;
use App\Support\Media\UploadConfiguration;
use App\Support\SecurityAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DeleteTrackedMovie
{
    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private UploadConfiguration $uploadConfiguration,
        private CacheManager $cacheManager,
    ) {}

    public function execute(MediaItem $mediaItem, User $actor, string $confirmationTitle): void
    {
        try {
            $repository = $this->cacheManager->store('database');

            if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
                throw new MovieDeletionException(
                    'movie_deletion_unavailable',
                    'Movie deletion is temporarily unavailable.',
                    503,
                );
            }

            $repository->getStore()
                ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($mediaItem, $actor, $confirmationTitle): void {
                    [$claim, $newlyConfirmed] = $this->claim(
                        $mediaItem->id,
                        $actor,
                        $confirmationTitle,
                    );

                    if ($newlyConfirmed) {
                        SecurityAudit::movieDeletionConfirmed($claim, $actor);
                    }

                    $this->deleteClaimedPrimary($claim);
                    $this->purgeDatabase($claim, $actor);
                    SecurityAudit::movieDeletionCompleted($claim, $actor);
                });
        } catch (LockTimeoutException $exception) {
            throw new MovieDeletionException(
                'movie_deletion_busy',
                'Movie storage is busy. Please try deletion again.',
                503,
                $exception,
            );
        } catch (MovieDeletionException|AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MovieDeletionException(
                'movie_deletion_failed',
                'The movie could not be deleted safely. Its database records were retained.',
                409,
                $exception,
            );
        }
    }

    /** @return array{TrackedMovieDeletionClaim, bool} */
    private function claim(int $mediaItemId, User $actor, string $confirmationTitle): array
    {
        return DB::transaction(function () use ($mediaItemId, $actor, $confirmationTitle): array {
            $mediaItem = MediaItem::query()->whereKey($mediaItemId)->lockForUpdate()->firstOrFail();
            [$uploads, $mediaFiles] = $this->lockedGraph($mediaItem);

            if ($confirmationTitle !== $mediaItem->title) {
                throw new MovieDeletionException(
                    'movie_deletion_confirmation_mismatch',
                    'Type the exact displayed movie title to confirm permanent deletion.',
                    422,
                );
            }

            $this->authorize($mediaItem, $uploads, $mediaFiles, $actor);
            $this->assertDatabaseGraph($mediaItem, $uploads, $mediaFiles);
            $this->assertNoUploadResidue($uploads);

            if ($mediaItem->deletion_claim !== null) {
                $claim = TrackedMovieDeletionClaim::fromArray($mediaItem->deletion_claim);
                $this->assertClaimMatchesDatabase($claim, $mediaItem, $mediaFiles);

                return [$claim, false];
            }

            $claim = $this->newClaim($mediaItem, $mediaFiles, $actor);
            $mediaItem->update([
                'deletion_claim' => $claim->toArray(),
                'deletion_requested_at' => now(),
            ]);

            return [$claim, true];
        }, attempts: 3);
    }

    /**
     * @return array{Collection<int, Upload>, Collection<int, MediaFile>}
     */
    private function lockedGraph(MediaItem $mediaItem): array
    {
        return [
            Upload::query()
                ->where('media_item_id', $mediaItem->getKey())
                ->oldest('id')
                ->lockForUpdate()
                ->get(),
            MediaFile::query()
                ->where('media_item_id', $mediaItem->getKey())
                ->oldest('id')
                ->lockForUpdate()
                ->get(),
        ];
    }

    /**
     * @param  Collection<int, Upload>  $uploads
     * @param  Collection<int, MediaFile>  $mediaFiles
     */
    private function authorize(
        MediaItem $mediaItem,
        Collection $uploads,
        Collection $mediaFiles,
        User $actor,
    ): void {
        if ($actor->isAdministrator()) {
            return;
        }

        if ($mediaItem->current_media_file_id !== null) {
            $currentMediaFile = $mediaFiles->firstWhere('id', $mediaItem->current_media_file_id);
            $sourceUpload = $currentMediaFile === null
                ? null
                : $uploads->firstWhere('id', $currentMediaFile->source_upload_id);

            if ($sourceUpload?->user_id === $actor->getKey()) {
                return;
            }
        } elseif ($uploads->isNotEmpty()
            && $uploads->every(fn (Upload $upload): bool => $upload->user_id === $actor->getKey())
        ) {
            return;
        }

        throw new AuthorizationException('Only this movie\'s owner or an administrator may delete it.');
    }

    /**
     * @param  Collection<int, Upload>  $uploads
     * @param  Collection<int, MediaFile>  $mediaFiles
     */
    private function assertDatabaseGraph(
        MediaItem $mediaItem,
        Collection $uploads,
        Collection $mediaFiles,
    ): void {
        foreach ($uploads as $upload) {
            if ($upload->status === UploadStatus::Failed) {
                throw new MovieDeletionException(
                    'movie_deletion_failed_upload',
                    'Discard or successfully retry every failed upload before deleting this movie.',
                );
            }

            if (! in_array($upload->status, [UploadStatus::Completed, UploadStatus::Cancelled, UploadStatus::Expired], true)) {
                throw new MovieDeletionException(
                    'movie_deletion_active_upload',
                    'Finish or cancel every active upload before deleting this movie.',
                );
            }
        }

        $uploadIds = $uploads->modelKeys();
        $mediaFileIds = $mediaFiles->modelKeys();

        foreach ($mediaFiles as $mediaFile) {
            $sourceUpload = $uploads->firstWhere('id', $mediaFile->source_upload_id);

            if ($sourceUpload === null
                || $sourceUpload->status !== UploadStatus::Completed
                || $sourceUpload->media_item_id !== $mediaItem->getKey()
            ) {
                throw $this->databaseConflict();
            }

            if ($mediaFile->replaced_by_media_file_id !== null
                && ! in_array($mediaFile->replaced_by_media_file_id, $mediaFileIds, true)
            ) {
                throw $this->databaseConflict();
            }
        }

        foreach ($uploads as $upload) {
            $mediaFile = $mediaFiles->firstWhere('source_upload_id', $upload->getKey());

            if (($upload->status === UploadStatus::Completed) !== ($mediaFile !== null)
                || ($upload->replaces_media_file_id !== null
                    && ! in_array($upload->replaces_media_file_id, $mediaFileIds, true))
            ) {
                throw $this->databaseConflict();
            }
        }

        $currentMediaFile = $mediaItem->current_media_file_id === null
            ? null
            : $mediaFiles->firstWhere('id', $mediaItem->current_media_file_id);
        $liveMediaFiles = $mediaFiles->filter(
            fn (MediaFile $mediaFile): bool => $mediaFile->replaced_at === null && $mediaFile->removed_at === null,
        );

        if (($mediaItem->current_media_file_id !== null && $currentMediaFile === null)
            || ($currentMediaFile !== null && (
                $currentMediaFile->replaced_at !== null
                || $currentMediaFile->removed_at !== null
                || $currentMediaFile->replaced_by_media_file_id !== null
                || $currentMediaFile->active_path_key !== MediaFile::activePathKey(
                    $currentMediaFile->disk_id,
                    $currentMediaFile->relative_path,
                )
            ))
            || ($currentMediaFile === null && $liveMediaFiles->isNotEmpty())
            || ($currentMediaFile !== null && ($liveMediaFiles->count() !== 1 || ! $liveMediaFiles->contains($currentMediaFile)))
        ) {
            throw $this->databaseConflict();
        }

        if ($mediaFileIds !== [] && (
            MediaItem::query()
                ->whereKeyNot($mediaItem->getKey())
                ->whereIn('current_media_file_id', $mediaFileIds)
                ->exists()
            || Upload::query()
                ->whereNotIn('id', $uploadIds)
                ->whereIn('replaces_media_file_id', $mediaFileIds)
                ->exists()
            || MediaFile::query()
                ->whereNotIn('id', $mediaFileIds)
                ->whereIn('replaced_by_media_file_id', $mediaFileIds)
                ->exists()
        )) {
            throw $this->databaseConflict();
        }
    }

    /** @param Collection<int, Upload> $uploads */
    private function assertNoUploadResidue(Collection $uploads): void
    {
        /** @var array<string, ConfiguredMediaDisk> $checkedDisks */
        $checkedDisks = [];

        foreach ($uploads as $upload) {
            $disk = $checkedDisks[$upload->disk_id] ??= $this->guardedDisk($upload->disk_id);

            try {
                $stagePath = $this->pathGuard->resolveChild($disk->root, $upload->staging_relative_path);
            } catch (MediaPathException $exception) {
                throw new MovieDeletionException(
                    'movie_deletion_upload_path_unsafe',
                    'A related upload path could not be verified safely.',
                    409,
                    $exception,
                );
            }

            $sidecarPath = $this->uploadConfiguration->tusMetadataPath.'/'.$upload->uuid.'.info';

            if ($this->filesystem->pathExists($stagePath) || $this->filesystem->pathExists($sidecarPath)) {
                throw new MovieDeletionException(
                    'movie_deletion_upload_residue',
                    'A related upload still owns staged data. Finish its cleanup before deleting this movie.',
                );
            }
        }
    }

    /** @param Collection<int, MediaFile> $mediaFiles */
    private function newClaim(MediaItem $mediaItem, Collection $mediaFiles, User $actor): TrackedMovieDeletionClaim
    {
        if ($mediaItem->current_media_file_id === null) {
            return TrackedMovieDeletionClaim::forOrphan(
                $mediaItem->id,
                $actor->id,
                $mediaItem->title,
            );
        }

        $mediaFile = $mediaFiles->firstWhere('id', $mediaItem->current_media_file_id);

        if ($mediaFile === null) {
            throw $this->databaseConflict();
        }

        $disk = $this->guardedDisk($mediaFile->disk_id);

        try {
            $path = $this->pathGuard->resolveChild($disk->root, $mediaFile->relative_path);
        } catch (MediaPathException $exception) {
            throw new MovieDeletionException(
                'movie_deletion_path_unsafe',
                'The tracked primary path could not be verified safely.',
                409,
                $exception,
            );
        }

        $deviceId = $this->filesystem->deviceId($path);
        $inodeId = $this->filesystem->inodeId($path);

        if ($this->filesystem->isSymbolicLink($path)
            || ! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $mediaFile->size_bytes
            || $deviceId === null
            || $inodeId === null
            || $deviceId !== $this->filesystem->deviceId($disk->root)
        ) {
            throw new MovieDeletionException(
                'movie_deletion_primary_invalid',
                'The exact tracked primary is missing or no longer matches its database identity.',
            );
        }

        return TrackedMovieDeletionClaim::forPrimary(
            mediaItemId: $mediaItem->id,
            actorUserId: $actor->id,
            title: $mediaItem->title,
            mediaFileId: $mediaFile->id,
            sourceUploadId: $mediaFile->source_upload_id,
            diskId: $mediaFile->disk_id,
            relativePath: $mediaFile->relative_path,
            sizeBytes: $mediaFile->size_bytes,
            deviceId: $deviceId,
            inodeId: $inodeId,
        );
    }

    /** @param Collection<int, MediaFile> $mediaFiles */
    private function assertClaimMatchesDatabase(
        TrackedMovieDeletionClaim $claim,
        MediaItem $mediaItem,
        Collection $mediaFiles,
    ): void {
        if ($claim->mediaItemId !== $mediaItem->getKey()
            || $claim->title !== $mediaItem->title
            || $claim->mediaFileId !== $mediaItem->current_media_file_id
        ) {
            throw $this->databaseConflict();
        }

        if (! $claim->hasPrimary()) {
            if ($mediaFiles->contains(
                fn (MediaFile $mediaFile): bool => $mediaFile->replaced_at === null && $mediaFile->removed_at === null,
            )) {
                throw $this->databaseConflict();
            }

            return;
        }

        $mediaFile = $mediaFiles->firstWhere('id', $claim->mediaFileId);

        if ($mediaFile === null
            || $mediaFile->source_upload_id !== $claim->sourceUploadId
            || $mediaFile->disk_id !== $claim->diskId
            || $mediaFile->relative_path !== $claim->relativePath
            || $mediaFile->size_bytes !== $claim->sizeBytes
        ) {
            throw $this->databaseConflict();
        }
    }

    private function deleteClaimedPrimary(TrackedMovieDeletionClaim $claim): void
    {
        if (! $claim->hasPrimary()) {
            return;
        }

        $primary = $claim->primaryIdentity();
        $disk = $this->guardedDisk($primary['disk_id']);

        try {
            $path = $this->pathGuard->resolveChild($disk->root, $primary['relative_path']);
        } catch (MediaPathException $exception) {
            throw new MovieDeletionException(
                'movie_deletion_path_unsafe',
                'The claimed primary path could not be verified safely.',
                409,
                $exception,
            );
        }

        if ($this->filesystem->pathExists($path)) {
            if ($this->filesystem->isSymbolicLink($path)
                || ! $this->filesystem->isRegularFile($path)
                || $this->filesystem->fileSize($path) !== $primary['size_bytes']
                || $this->filesystem->deviceId($path) !== $primary['device_id']
                || $this->filesystem->inodeId($path) !== $primary['inode_id']
                || ! $this->filesystem->deleteFile($path)
            ) {
                throw new MovieDeletionException(
                    'movie_deletion_primary_changed',
                    'The exact tracked primary changed or could not be deleted.',
                );
            }
        }

        if ($this->filesystem->pathExists($path)) {
            throw new MovieDeletionException(
                'movie_deletion_primary_delete_failed',
                'The exact tracked primary could not be deleted.',
            );
        }

        $this->removeEmptyMovieDirectory($disk, $primary['relative_path']);
    }

    private function removeEmptyMovieDirectory(ConfiguredMediaDisk $disk, ?string $relativePath): void
    {
        if ($relativePath === null || dirname($relativePath) === '.') {
            return;
        }

        try {
            $directory = $this->pathGuard->resolveChild($disk->root, dirname($relativePath));
        } catch (MediaPathException $exception) {
            throw new MovieDeletionException(
                'movie_deletion_directory_unsafe',
                'The movie directory could not be verified safely.',
                409,
                $exception,
            );
        }

        if (! $this->filesystem->pathExists($directory)) {
            return;
        }

        if ($this->filesystem->isSymbolicLink($directory) || ! $this->filesystem->isDirectory($directory)) {
            throw new MovieDeletionException(
                'movie_deletion_directory_unsafe',
                'The movie directory is no longer a safe directory.',
            );
        }

        if ($this->filesystem->isDirectoryEmpty($directory)
            && ! $this->filesystem->removeDirectoryIfEmpty($directory)
        ) {
            throw new MovieDeletionException(
                'movie_deletion_directory_failed',
                'The empty movie directory could not be removed safely.',
            );
        }
    }

    private function purgeDatabase(TrackedMovieDeletionClaim $claim, User $actor): void
    {
        DB::transaction(function () use ($claim, $actor): void {
            $mediaItem = MediaItem::query()->whereKey($claim->mediaItemId)->lockForUpdate()->firstOrFail();
            [$uploads, $mediaFiles] = $this->lockedGraph($mediaItem);

            $this->authorize($mediaItem, $uploads, $mediaFiles, $actor);
            $this->assertDatabaseGraph($mediaItem, $uploads, $mediaFiles);
            $this->assertNoUploadResidue($uploads);

            if ($mediaItem->deletion_claim === null) {
                throw $this->databaseConflict();
            }

            $persistedClaim = TrackedMovieDeletionClaim::fromArray($mediaItem->deletion_claim);

            if ($persistedClaim->toArray() !== $claim->toArray()) {
                throw $this->databaseConflict();
            }

            $this->assertClaimMatchesDatabase($claim, $mediaItem, $mediaFiles);
            $this->assertClaimedPrimaryAbsent($claim);

            $mediaFileIds = $mediaFiles->modelKeys();

            MediaItem::query()->whereKey($mediaItem->getKey())->update(['current_media_file_id' => null]);
            Upload::query()->where('media_item_id', $mediaItem->getKey())->update(['replaces_media_file_id' => null]);
            MediaFile::query()->where('media_item_id', $mediaItem->getKey())->update(['replaced_by_media_file_id' => null]);

            if ($mediaFileIds !== []) {
                MediaFile::query()->whereKey($mediaFileIds)->delete();
            }

            Upload::query()->where('media_item_id', $mediaItem->getKey())->delete();
            MediaItem::query()->whereKey($mediaItem->getKey())->delete();
        }, attempts: 3);
    }

    private function assertClaimedPrimaryAbsent(TrackedMovieDeletionClaim $claim): void
    {
        if (! $claim->hasPrimary()) {
            return;
        }

        $primary = $claim->primaryIdentity();
        $disk = $this->guardedDisk($primary['disk_id']);

        try {
            $path = $this->pathGuard->resolveChild($disk->root, $primary['relative_path']);
        } catch (MediaPathException $exception) {
            throw new MovieDeletionException(
                'movie_deletion_path_unsafe',
                'The claimed primary path could not be verified safely.',
                409,
                $exception,
            );
        }

        if ($this->filesystem->pathExists($path)) {
            throw new MovieDeletionException(
                'movie_deletion_primary_still_exists',
                'The tracked primary still exists, so database history was retained.',
            );
        }
    }

    private function guardedDisk(?string $diskId): ConfiguredMediaDisk
    {
        $disk = $diskId === null ? null : $this->diskRegistry->find($diskId);

        if ($disk === null
            || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy
        ) {
            throw new MovieDeletionException(
                'movie_deletion_disk_unavailable',
                'A related media disk is unavailable or no longer matches its configured identity.',
                503,
            );
        }

        return $disk;
    }

    private function databaseConflict(): MovieDeletionException
    {
        return new MovieDeletionException(
            'movie_deletion_database_conflict',
            'The movie database graph changed or is inconsistent. Nothing else was deleted.',
        );
    }
}
