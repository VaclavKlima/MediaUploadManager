<?php

namespace App\Actions;

use App\Exceptions\MovieReidentificationException;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\MediaItemReidentification;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\SecurityAudit;
use App\Support\Tmdb\Data\MovieDetails;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ReidentifyMovie
{
    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private PreviewMovieReidentification $preview,
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private JellyfinMoviePathBuilder $pathBuilder,
        private CacheManager $cacheManager,
    ) {}

    public function execute(MediaItem $mediaItem, User $actor, MovieDetails $details): MediaItemReidentification
    {
        try {
            $repository = $this->cacheManager->store('database');

            if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
                throw $this->failure('reidentification_unavailable', 'Movie re-identification is temporarily unavailable.', 503);
            }

            $result = $repository->getStore()
                ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($mediaItem, $actor, $details): MediaItemReidentification {
                    [$operation, $newlyClaimed] = $this->claim($mediaItem->id, $actor, $details);

                    try {
                        if ($newlyClaimed) {
                            SecurityAudit::movieReidentificationConfirmed($operation, $actor);
                        }

                        $this->moveClaimedFile($operation);
                        $operation = $this->complete($operation->id, $actor);
                        SecurityAudit::movieReidentificationCompleted($operation, $actor);
                    } catch (MovieReidentificationException $exception) {
                        $this->markFailed($operation, $exception);

                        throw $exception;
                    } catch (Throwable $exception) {
                        $failure = $this->failure(
                            'reidentification_failed',
                            'The movie could not be re-identified safely. Retry the same identity.',
                            409,
                            $exception,
                        );
                        $this->markFailed($operation, $failure);

                        throw $failure;
                    }

                    return $operation;
                });

            if (! $result instanceof MediaItemReidentification) {
                throw $this->failure('reidentification_failed', 'The movie re-identification result was invalid.');
            }

            return $result;
        } catch (LockTimeoutException $exception) {
            throw $this->failure(
                'reidentification_busy',
                'Movie storage is busy. Please try re-identification again.',
                503,
                $exception,
            );
        } catch (MovieReidentificationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->failure(
                'reidentification_failed',
                'The movie could not be re-identified safely. Retry the same identity.',
                409,
                $exception,
            );
        }
    }

    /** @return array{MediaItemReidentification, bool} */
    private function claim(int $mediaItemId, User $actor, MovieDetails $details): array
    {
        return DB::transaction(function () use ($mediaItemId, $actor, $details): array {
            $mediaItem = MediaItem::query()->whereKey($mediaItemId)->lockForUpdate()->firstOrFail();

            if (! $actor->isAdministrator()) {
                throw $this->failure('forbidden', 'Only an administrator may re-identify movies.', 403);
            }

            $existing = MediaItemReidentification::query()
                ->whereBelongsTo($mediaItem)
                ->whereNull('completed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (($existing->new_metadata_snapshot['tmdb_id'] ?? null) !== $details->tmdbId) {
                    throw $this->failure(
                        'operation_target_mismatch',
                        'Retry must use the identity already pinned by the failed operation.',
                    );
                }

                $inspection = $this->preview->execute($mediaItem, $details);
                $this->assertEligible($inspection);
                $existing->update([
                    'status' => 'pending',
                    'error_code' => null,
                    'error_detail' => null,
                    'failed_at' => null,
                ]);

                return [$existing->refresh(), false];
            }

            $inspection = $this->preview->execute($mediaItem, $details);
            $this->assertEligible($inspection);
            $this->removeEmptyTargetPlaceholder($mediaItem, $details->mediaItemSnapshot());
            $currentFile = $mediaItem->current_media_file_id === null
                ? null
                : MediaFile::query()->whereKey($mediaItem->current_media_file_id)->lockForUpdate()->first();
            $claim = [
                'media_item_id' => $mediaItem->id,
                'actor_user_id' => $actor->id,
                'source_media_file_id' => null,
                'source_upload_id' => null,
                'old_metadata_snapshot' => $this->metadataSnapshot($mediaItem),
                'new_metadata_snapshot' => $details->mediaItemSnapshot(),
                'disk_id' => null,
                'source_relative_path' => null,
                'destination_relative_path' => null,
                'size_bytes' => null,
                'device_id' => null,
                'inode_id' => null,
                'status' => 'pending',
                'claimed_at' => now(),
            ];

            if ($currentFile !== null) {
                $disk = $this->healthyDisk($currentFile->disk_id);
                $source = $this->pathGuard->resolveChild($disk->root, $currentFile->relative_path);
                $destination = $this->pathBuilder
                    ->build(new MediaItem($details->mediaItemSnapshot()), basename($currentFile->relative_path));

                if (! $this->filesystem->isRegularFile($source)
                    || $this->filesystem->fileSize($source) !== $currentFile->size_bytes
                ) {
                    throw $this->failure('source_changed', 'The tracked source file is missing, changed, or symbolic.');
                }

                $deviceId = $this->filesystem->deviceId($source);
                $inodeId = $this->filesystem->inodeId($source);

                if ($deviceId === null || $inodeId === null || $this->filesystem->deviceId($disk->root) !== $deviceId) {
                    throw $this->failure('source_changed', 'The tracked source file is no longer on its configured disk.');
                }

                $claim = [
                    ...$claim,
                    'source_media_file_id' => $currentFile->id,
                    'source_upload_id' => $currentFile->source_upload_id,
                    'disk_id' => $currentFile->disk_id,
                    'source_relative_path' => $currentFile->relative_path,
                    'destination_relative_path' => $destination->relativePath,
                    'size_bytes' => $currentFile->size_bytes,
                    'device_id' => $deviceId,
                    'inode_id' => $inodeId,
                ];
            }

            return [MediaItemReidentification::query()->create($claim), true];
        }, attempts: 3);
    }

    private function moveClaimedFile(MediaItemReidentification $operation): void
    {
        if ($operation->source_media_file_id === null) {
            return;
        }

        $disk = $this->healthyDisk((string) $operation->disk_id);
        $source = $this->pathGuard->resolveChild($disk->root, (string) $operation->source_relative_path);
        $destination = $this->pathGuard->resolveChild($disk->root, (string) $operation->destination_relative_path);
        $destinationDirectory = dirname($destination);
        $sourceExists = $this->filesystem->pathExists($source);
        $destinationExists = $this->filesystem->pathExists($destination);

        if ($sourceExists) {
            $this->assertClaimedFile($source, $operation);
        }

        if ($destinationExists) {
            $this->assertClaimedFile($destination, $operation);
        }

        if (! $sourceExists && ! $destinationExists) {
            throw $this->failure('claimed_file_missing', 'The file pinned by the re-identification claim is missing.');
        }

        if ($sourceExists && ! $destinationExists) {
            if ($this->filesystem->pathExists($destinationDirectory)) {
                if (! $this->filesystem->isDirectory($destinationDirectory)
                    || $this->filesystem->isSymbolicLink($destinationDirectory)
                    || ! $this->filesystem->isDirectoryEmpty($destinationDirectory)
                ) {
                    throw $this->failure('destination_occupied', 'The proposed canonical destination is already occupied.');
                }
            } elseif (! $this->filesystem->createDirectory($destinationDirectory)) {
                throw $this->failure('destination_unavailable', 'The canonical destination directory could not be created.');
            }

            if (! $this->filesystem->createHardLinkExclusively($source, $destination)) {
                throw $this->failure('destination_occupied', 'The canonical destination could not be reserved exclusively.');
            }

            $this->assertClaimedFile($destination, $operation);
            $destinationExists = true;
        }

        if ($sourceExists && ! $this->filesystem->sameInode($source, $destination)) {
            throw $this->failure('inode_mismatch', 'Source and destination do not reference the claimed file.');
        }

        if ($sourceExists && ! $this->filesystem->deleteFile($source)) {
            throw $this->failure('source_unlink_failed', 'The claimed source path could not be released.');
        }

        $this->assertClaimedFile($destination, $operation);
        $this->removeOldDirectoryIfEmpty($disk->root, dirname($source));
    }

    private function complete(int $operationId, User $actor): MediaItemReidentification
    {
        return DB::transaction(function () use ($operationId, $actor): MediaItemReidentification {
            $operation = MediaItemReidentification::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();

            if ($operation->completed_at !== null) {
                return $operation;
            }

            $mediaItem = MediaItem::query()->whereKey($operation->media_item_id)->lockForUpdate()->firstOrFail();

            if ($operation->source_media_file_id === null) {
                if ($mediaItem->current_media_file_id !== null) {
                    throw $this->failure('stale_primary', 'The orphan movie gained a current primary after confirmation.');
                }

                $mediaItem->reidentify($operation->new_metadata_snapshot);
            } else {
                $oldMediaFile = MediaFile::query()
                    ->whereKey($operation->source_media_file_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($mediaItem->current_media_file_id !== $oldMediaFile->id
                    || $oldMediaFile->media_item_id !== $mediaItem->id
                    || $oldMediaFile->active_path_key !== MediaFile::activePathKey(
                        (string) $operation->disk_id,
                        (string) $operation->source_relative_path,
                    )
                ) {
                    throw $this->failure('stale_primary', 'The current primary changed after re-identification was confirmed.');
                }

                $disk = $this->healthyDisk((string) $operation->disk_id);
                $destination = $this->pathGuard->resolveChild($disk->root, (string) $operation->destination_relative_path);
                $this->assertClaimedFile($destination, $operation);
                $mediaItem->reidentify($operation->new_metadata_snapshot);
                $oldMediaFile->update([
                    'removed_at' => now(),
                    'removal_reason' => 'reidentified',
                ]);
                $newMediaFile = MediaFile::query()->create([
                    'media_item_id' => $mediaItem->id,
                    'source_upload_id' => null,
                    'imported_by_user_id' => $actor->id,
                    'import_provenance' => [
                        'type' => 'reidentification',
                        'media_item_reidentification_id' => $operation->id,
                        'previous_media_file_id' => $oldMediaFile->id,
                        'relocation_proof' => [
                            'type' => 'inode',
                            'size_bytes' => $operation->size_bytes,
                            'device_id' => $operation->device_id,
                            'inode_id' => $operation->inode_id,
                        ],
                    ],
                    'disk_id' => $operation->disk_id,
                    'relative_path' => $operation->destination_relative_path,
                    'size_bytes' => $oldMediaFile->size_bytes,
                    'container' => $oldMediaFile->container,
                    'duration_milliseconds' => $oldMediaFile->duration_milliseconds,
                    'video_metadata' => $oldMediaFile->video_metadata,
                    'audio_metadata' => $oldMediaFile->audio_metadata,
                    'probe_snapshot' => $oldMediaFile->probe_snapshot,
                    'finalized_at' => now(),
                ]);
                $mediaItem->update(['current_media_file_id' => $newMediaFile->id]);
            }

            $operation->update([
                'status' => 'completed',
                'error_code' => null,
                'error_detail' => null,
                'failed_at' => null,
                'completed_at' => now(),
            ]);

            return $operation->refresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $inspection */
    private function assertEligible(array $inspection): void
    {
        if ($inspection['eligible'] === true) {
            return;
        }

        $blocker = $inspection['blocker'];

        throw $this->failure(
            is_array($blocker) && is_string($blocker['code'] ?? null) ? $blocker['code'] : 'reidentification_blocked',
            is_array($blocker) && is_string($blocker['message'] ?? null)
                ? $blocker['message']
                : 'The movie cannot be re-identified safely.',
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function removeEmptyTargetPlaceholder(MediaItem $source, array $snapshot): void
    {
        $targets = MediaItem::query()
            ->whereKeyNot($source->id)
            ->where(function ($query) use ($snapshot): void {
                $query->where('tmdb_id', $snapshot['tmdb_id']);

                if (is_string($snapshot['imdb_id'] ?? null)) {
                    $query->orWhere('imdb_id', $snapshot['imdb_id']);
                }
            })
            ->lockForUpdate()
            ->get();

        if ($targets->isEmpty()) {
            return;
        }

        if ($targets->count() > 1) {
            throw $this->failure('identity_conflict', 'The selected movie identity is already tracked.');
        }

        $target = $targets->sole();

        if ($target->current_media_file_id !== null
            || $target->deletion_claim !== null
            || $target->deletion_requested_at !== null
            || $target->uploads()->exists()
            || $target->mediaFiles()->exists()
            || $target->reidentifications()->exists()
        ) {
            throw $this->failure('identity_conflict', 'The selected movie identity is already tracked.');
        }

        $target->delete();
    }

    private function healthyDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->find($diskId);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw $this->failure('disk_unavailable', 'The current movie disk is unavailable or unhealthy.');
        }

        return $disk;
    }

    private function assertClaimedFile(string $path, MediaItemReidentification $operation): void
    {
        if (! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $operation->size_bytes
            || $this->filesystem->deviceId($path) !== $operation->device_id
            || $this->filesystem->inodeId($path) !== $operation->inode_id
        ) {
            throw $this->failure('claimed_file_changed', 'A file pinned by the re-identification claim has changed.');
        }
    }

    private function removeOldDirectoryIfEmpty(string $configuredRoot, string $directory): void
    {
        try {
            $root = $this->pathGuard->resolveRoot($configuredRoot);

            if ($directory !== $root && $this->filesystem->isDirectoryEmpty($directory)) {
                $this->filesystem->removeDirectoryIfEmpty($directory);
            }
        } catch (Throwable) {
            // Empty-directory cleanup is best effort and never authorizes recursive deletion.
        }
    }

    /** @return array<string, mixed> */
    private function metadataSnapshot(MediaItem $mediaItem): array
    {
        return $mediaItem->only([
            'tmdb_id', 'imdb_id', 'title', 'original_title', 'release_date', 'release_year',
            'overview', 'poster_path', 'original_language', 'metadata_version', 'metadata_snapshot',
        ]);
    }

    private function markFailed(MediaItemReidentification $operation, MovieReidentificationException $failure): void
    {
        MediaItemReidentification::query()
            ->whereKey($operation->id)
            ->whereNull('completed_at')
            ->update([
                'status' => 'failed',
                'error_code' => $failure->errorCode,
                'error_detail' => $failure->getMessage(),
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function failure(
        string $code,
        string $message,
        int $status = 409,
        ?Throwable $previous = null,
    ): MovieReidentificationException {
        return new MovieReidentificationException($code, $message, $status, $previous);
    }
}
