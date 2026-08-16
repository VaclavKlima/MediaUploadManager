<?php

namespace App\Actions\Series;

use App\Actions\CreateOrReplayUploadReservation;
use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Exceptions\SeriesOperationException;
use App\Models\EpisodeRenameOperation;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\SeriesUploadBatch;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\UploadConfiguration;
use App\Support\SecurityAudit;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final readonly class DeleteSeriesMedia
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

    public function execute(
        Series $series,
        string $scopeType,
        int $scopeId,
        User $actor,
        bool $confirmed,
        ?string $confirmationName = null,
    ): SeriesDeletionOperation {
        $repository = $this->cacheManager->store('database');

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw $this->failure('series_deletion_unavailable', 'Show media deletion is temporarily unavailable.', 503);
        }

        try {
            $result = $repository->getStore()
                ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($series, $scopeType, $scopeId, $actor, $confirmed, $confirmationName): SeriesDeletionOperation {
                    [$operation, $newlyClaimed] = $this->claim(
                        $series->id,
                        $scopeType,
                        $scopeId,
                        $actor,
                        $confirmed,
                        $confirmationName,
                    );

                    try {
                        if ($newlyClaimed) {
                            SecurityAudit::seriesDeletionConfirmed($operation, $actor);
                        }

                        $this->unlinkManifest($operation);
                        $operation = $this->complete($operation->id, $actor);
                        SecurityAudit::seriesDeletionCompleted($operation, $actor);

                        return $operation;
                    } catch (SeriesOperationException $exception) {
                        $this->markFailed($operation, $exception);
                        throw $exception;
                    } catch (Throwable $exception) {
                        $failure = $this->failure(
                            'series_deletion_failed',
                            'The claimed Show media could not be deleted safely. Retry the same operation.',
                            409,
                            $exception,
                        );
                        $this->markFailed($operation, $failure);
                        throw $failure;
                    }
                });

            if (! $result instanceof SeriesDeletionOperation) {
                throw $this->failure('series_deletion_failed', 'The Show deletion result was invalid.');
            }

            return $result;
        } catch (LockTimeoutException $exception) {
            throw $this->failure('series_deletion_busy', 'Show storage is busy. Please retry.', 503, $exception);
        }
    }

    /** @return array{SeriesDeletionOperation, bool} */
    private function claim(
        int $seriesId,
        string $scopeType,
        int $scopeId,
        User $actor,
        bool $confirmed,
        ?string $confirmationName,
    ): array {
        return DB::transaction(function () use ($seriesId, $scopeType, $scopeId, $actor, $confirmed, $confirmationName): array {
            if (! in_array($scopeType, ['episode', 'season', 'series'], true)) {
                throw $this->failure('series_deletion_scope_invalid', 'The Show deletion scope is invalid.', 422);
            }

            $series = Series::query()->whereKey($seriesId)->lockForUpdate()->firstOrFail();

            if (! $confirmed) {
                throw $this->failure('series_deletion_confirmation_required', 'Confirm that this media deletion is permanent.', 422);
            }

            if ($scopeType === 'series' && (! is_string($confirmationName) || ! hash_equals($series->name, $confirmationName))) {
                throw $this->failure('series_deletion_name_mismatch', 'The confirmation name must exactly match the Show name.', 422);
            }

            $episodeIds = $this->episodeIds($series, $scopeType, $scopeId);
            $currentEpisodes = SeriesEpisode::query()->whereIn('id', $episodeIds)
                ->whereNotNull('current_media_file_id')
                ->get(['current_media_file_id']);
            $currentMediaFileIds = [];

            foreach ($currentEpisodes as $currentEpisode) {
                if ($currentEpisode->current_media_file_id === null) {
                    throw $this->failure('series_deletion_database_conflict', 'A current Show media pointer is invalid.');
                }

                $currentMediaFileIds[] = $currentEpisode->current_media_file_id;
            }

            $mediaFiles = MediaFile::query()->whereKey($currentMediaFileIds)
                ->whereNotNull('active_path_key')->oldest('id')->lockForUpdate()->get();

            if ($mediaFiles->count() !== count($currentMediaFileIds)) {
                throw $this->failure('series_deletion_database_conflict', 'A current Show media pointer is invalid or already released.');
            }

            $uploads = Upload::query()->whereIn('series_episode_id', $episodeIds)
                ->oldest('id')->lockForUpdate()->get();
            $this->authorize($scopeType, $actor, $mediaFiles);
            $this->assertUploadsResolved($uploads);
            $this->assertNoTusResidue($uploads);

            $existing = SeriesDeletionOperation::query()->where('series_id', $series->id)
                ->whereNot('status', 'completed')->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->scope_type !== $scopeType || $existing->scope_id !== $scopeId) {
                    throw $this->failure('series_deletion_overlap', 'Another deletion operation is unresolved for this Show.');
                }

                return [$existing, false];
            }

            if (EpisodeRenameOperation::query()->whereIn('series_episode_id', $episodeIds)
                ->whereNot('status', 'completed')->exists()
            ) {
                throw $this->failure('series_deletion_rename_unresolved', 'Resolve the episode rename before deleting Show media.');
            }

            if ($scopeType !== 'series' && $mediaFiles->isEmpty()) {
                throw $this->failure('series_deletion_empty', 'The selected scope has no current media to delete.', 422);
            }

            $manifest = $mediaFiles->map(fn (MediaFile $mediaFile): array => $this->manifestItem($mediaFile))->all();
            $manifestJson = $this->manifestJson($manifest);
            $operation = SeriesDeletionOperation::query()->create([
                'actor_user_id' => $actor->id,
                'series_id' => $series->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'series_name' => $series->name,
                'status' => 'pending',
                'manifest' => $manifest,
                'manifest_hash' => hash('sha256', $manifestJson),
                'file_count' => count($manifest),
                'total_size_bytes' => array_sum(array_column($manifest, 'size_bytes')),
                'confirmed_at' => now(),
            ]);

            return [$operation, true];
        }, attempts: 3);
    }

    /** @return list<int> */
    private function episodeIds(Series $series, string $scopeType, int $scopeId): array
    {
        if ($scopeType === 'episode') {
            $episode = SeriesEpisode::query()->whereKey($scopeId)
                ->whereIn('series_season_id', $series->seasons()->select('id'))->firstOrFail();

            return [$episode->id];
        }

        if ($scopeType === 'season') {
            $season = SeriesSeason::query()->whereKey($scopeId)->whereBelongsTo($series)->firstOrFail();

            return array_values($season->episodes()->get(['id'])
                ->map(fn (SeriesEpisode $episode): int => $episode->id)
                ->all());
        }

        if ($scopeId !== $series->id) {
            throw $this->failure('series_deletion_scope_invalid', 'The Show deletion scope does not match.', 404);
        }

        return array_values($series->episodes()->get(['series_episodes.id'])
            ->map(fn (SeriesEpisode $episode): int => $episode->id)
            ->all());
    }

    /** @param Collection<int, MediaFile> $mediaFiles */
    private function authorize(string $scopeType, User $actor, Collection $mediaFiles): void
    {
        if ($actor->isAdministrator()) {
            return;
        }

        if ($scopeType !== 'episode' || $mediaFiles->count() !== 1) {
            throw $this->failure('series_deletion_forbidden', 'Only an administrator may delete season or whole-Show media.', 403);
        }

        $mediaFile = $mediaFiles->first();
        $ownerId = $mediaFile?->source_upload_id === null
            ? $mediaFile?->imported_by_user_id
            : Upload::query()->whereKey($mediaFile->source_upload_id)->value('user_id');

        if ($ownerId !== $actor->id) {
            throw $this->failure('series_deletion_forbidden', 'Only this episode\'s owner or an administrator may delete its media.', 403);
        }
    }

    /** @param Collection<int, Upload> $uploads */
    private function assertUploadsResolved(Collection $uploads): void
    {
        foreach ($uploads as $upload) {
            if ($upload->status === UploadStatus::Failed) {
                throw $this->failure('series_deletion_failed_upload', 'Discard or successfully retry every failed upload first.');
            }

            if (! in_array($upload->status, [UploadStatus::Completed, UploadStatus::Cancelled, UploadStatus::Expired], true)) {
                throw $this->failure('series_deletion_active_upload', 'Finish or cancel every active upload first.');
            }
        }
    }

    /** @param Collection<int, Upload> $uploads */
    private function assertNoTusResidue(Collection $uploads): void
    {
        foreach ($uploads as $upload) {
            $disk = $this->guardedDisk($upload->disk_id);
            $stage = $this->pathGuard->resolveChild($disk->root, $upload->staging_relative_path);
            $sidecar = $this->uploadConfiguration->tusMetadataPath.'/'.$upload->uuid.'.info';

            if ($this->filesystem->pathExists($stage) || $this->filesystem->pathExists($sidecar)) {
                throw $this->failure('series_deletion_upload_residue', 'A related upload still owns staged data. Finish its cleanup first.');
            }
        }
    }

    /** @return array<string, int|string|null> */
    private function manifestItem(MediaFile $mediaFile): array
    {
        $disk = $this->guardedDisk($mediaFile->disk_id);
        $path = $this->pathGuard->resolveChild($disk->root, $mediaFile->relative_path);
        $deviceId = $this->filesystem->deviceId($path);
        $inodeId = $this->filesystem->inodeId($path);

        if ($deviceId === null || $inodeId === null
            || $deviceId !== $this->filesystem->deviceId($disk->root)
            || ! $this->matches($path, $mediaFile->size_bytes, $deviceId, $inodeId)
        ) {
            throw $this->failure('series_deletion_primary_changed', 'An exact tracked episode file is missing or changed.');
        }

        return [
            'media_file_id' => $mediaFile->id,
            'series_episode_id' => $mediaFile->series_episode_id,
            'source_upload_id' => $mediaFile->source_upload_id,
            'disk_id' => $mediaFile->disk_id,
            'relative_path' => $mediaFile->relative_path,
            'size_bytes' => $mediaFile->size_bytes,
            'device_id' => $deviceId,
            'inode_id' => $inodeId,
        ];
    }

    private function unlinkManifest(SeriesDeletionOperation $operation): void
    {
        if (! hash_equals($operation->manifest_hash, hash('sha256', $this->manifestJson($operation->manifest)))) {
            throw $this->failure('series_deletion_manifest_changed', 'The persisted exact-file manifest is invalid.');
        }

        foreach ($operation->manifest as $item) {
            $diskId = $item['disk_id'] ?? null;
            $relativePath = $item['relative_path'] ?? null;
            $size = $item['size_bytes'] ?? null;
            $deviceId = $item['device_id'] ?? null;
            $inodeId = $item['inode_id'] ?? null;

            if (! is_string($diskId) || ! is_string($relativePath) || ! is_int($size)
                || ! is_int($deviceId) || ! is_int($inodeId)
            ) {
                throw $this->failure('series_deletion_manifest_invalid', 'The persisted exact-file manifest is incomplete.');
            }

            $disk = $this->guardedDisk($diskId);
            $path = $this->pathGuard->resolveChild($disk->root, $relativePath);

            if ($this->filesystem->pathExists($path)
                && (! $this->matches($path, $size, $deviceId, $inodeId) || ! $this->filesystem->deleteFile($path))
            ) {
                throw $this->failure('series_deletion_primary_changed', 'A claimed episode file changed or could not be deleted.');
            }

            if ($this->filesystem->pathExists($path)) {
                throw $this->failure('series_deletion_unlink_failed', 'A claimed episode file could not be deleted.');
            }

            $this->pruneEmptyAncestors($disk, dirname($relativePath));
        }
    }

    private function complete(int $operationId, User $actor): SeriesDeletionOperation
    {
        return DB::transaction(function () use ($operationId, $actor): SeriesDeletionOperation {
            $operation = SeriesDeletionOperation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();

            if ($operation->status === 'completed') {
                return $operation;
            }

            foreach ($operation->manifest as $item) {
                $this->assertManifestPathAbsent($item);
            }

            if ($operation->scope_type === 'series') {
                $this->purgeSeriesGraph($operation, $actor);
            } else {
                $mediaFileIds = collect($operation->manifest)->pluck('media_file_id')
                    ->filter(fn (mixed $value): bool => is_int($value))->values()->all();
                $episodeIds = collect($operation->manifest)->pluck('series_episode_id')
                    ->filter(fn (mixed $value): bool => is_int($value))->unique()->values()->all();
                $files = MediaFile::query()->whereKey($mediaFileIds)->lockForUpdate()->get();

                if ($files->count() !== count($mediaFileIds)) {
                    throw $this->failure('series_deletion_database_conflict', 'The Show media graph changed after confirmation.');
                }

                foreach ($files as $mediaFile) {
                    $mediaFile->update(['removed_at' => now(), 'removal_reason' => $operation->scope_type.'_media_deleted']);
                }

                SeriesEpisode::query()->whereIn('id', $episodeIds)->whereIn('current_media_file_id', $mediaFileIds)
                    ->update(['current_media_file_id' => null]);
                $series = Series::query()->findOrFail($operation->series_id);
                $latest = MediaFile::query()->whereIn('series_episode_id', $series->episodes()->select('series_episodes.id'))
                    ->whereNotNull('active_path_key')->max('finalized_at');
                $series->update(['last_episode_finalized_at' => $latest]);
            }

            $operation->update([
                'status' => 'completed',
                'error_code' => null,
                'error_detail' => null,
                'completed_at' => now(),
                'failed_at' => null,
            ]);

            return $operation->refresh();
        }, attempts: 3);
    }

    private function purgeSeriesGraph(SeriesDeletionOperation $operation, User $actor): void
    {
        $series = Series::query()->whereKey($operation->series_id)->lockForUpdate()->firstOrFail();

        if (! $actor->isAdministrator() || $series->name !== $operation->series_name) {
            throw $this->failure('series_deletion_database_conflict', 'The whole-Show deletion claim no longer matches.');
        }

        $episodeIds = $series->episodes()->pluck('series_episodes.id')->all();
        $mediaFileIds = MediaFile::query()->whereIn('series_episode_id', $episodeIds)->pluck('id')->all();

        SeriesEpisode::query()->whereIn('id', $episodeIds)->update(['current_media_file_id' => null]);
        Upload::query()->whereIn('series_episode_id', $episodeIds)->update(['replaces_media_file_id' => null]);
        MediaFile::query()->whereIn('id', $mediaFileIds)->update(['replaced_by_media_file_id' => null]);
        EpisodeRenameOperation::query()->whereIn('series_episode_id', $episodeIds)->delete();
        MediaFile::query()->whereIn('id', $mediaFileIds)->delete();
        Upload::query()->whereIn('series_episode_id', $episodeIds)->delete();
        SeriesUploadBatch::query()->where('series_id', $series->id)->delete();
        SeriesEpisode::query()->whereIn('id', $episodeIds)->delete();
        SeriesSeason::query()->where('series_id', $series->id)->delete();
        Series::query()->whereKey($series->id)->delete();
    }

    /** @param array<string, int|string|null> $item */
    private function assertManifestPathAbsent(array $item): void
    {
        $diskId = $item['disk_id'] ?? null;
        $relativePath = $item['relative_path'] ?? null;

        if (! is_string($diskId) || ! is_string($relativePath)) {
            throw $this->failure('series_deletion_manifest_invalid', 'The persisted exact-file manifest is incomplete.');
        }

        $path = $this->pathGuard->resolveChild($this->guardedDisk($diskId)->root, $relativePath);

        if ($this->filesystem->pathExists($path)) {
            throw $this->failure('series_deletion_primary_still_exists', 'A claimed episode file still exists, so database history was retained.');
        }
    }

    private function pruneEmptyAncestors(ConfiguredMediaDisk $disk, string $relativeDirectory): void
    {
        $root = $this->pathGuard->resolveRoot($disk->root);
        $directory = $this->pathGuard->resolveChild($disk->root, $relativeDirectory);

        while ($directory !== $root && str_starts_with($directory, $root.'/')) {
            if (! $this->filesystem->isDirectoryEmpty($directory) || ! $this->filesystem->removeDirectoryIfEmpty($directory)) {
                break;
            }

            $directory = dirname($directory);
        }
    }

    private function matches(string $path, int $size, int $deviceId, int $inodeId): bool
    {
        return ! $this->filesystem->isSymbolicLink($path)
            && $this->filesystem->isRegularFile($path)
            && $this->filesystem->fileSize($path) === $size
            && $this->filesystem->deviceId($path) === $deviceId
            && $this->filesystem->inodeId($path) === $inodeId;
    }

    private function guardedDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->findRoot($diskId, MediaRootKind::Series);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw $this->failure('series_deletion_disk_unavailable', 'A Show disk is unavailable or unhealthy.', 503);
        }

        return $disk;
    }

    /** @param array<int, array<string, int|string|null>> $manifest */
    private function manifestJson(array $manifest): string
    {
        try {
            return json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw $this->failure('series_deletion_manifest_invalid', 'The exact-file manifest could not be encoded.', 409, $exception);
        }
    }

    private function markFailed(SeriesDeletionOperation $operation, SeriesOperationException $exception): void
    {
        SeriesDeletionOperation::query()->whereKey($operation->id)->whereNot('status', 'completed')->update([
            'status' => 'failed',
            'error_code' => $exception->errorCode,
            'error_detail' => $exception->getMessage(),
            'failed_at' => now(),
        ]);
    }

    private function failure(
        string $code,
        string $message,
        int $status = 409,
        ?Throwable $previous = null,
    ): SeriesOperationException {
        return new SeriesOperationException($code, $message, $status, $previous);
    }
}
