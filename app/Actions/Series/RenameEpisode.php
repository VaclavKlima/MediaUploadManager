<?php

namespace App\Actions\Series;

use App\Actions\CreateOrReplayUploadReservation;
use App\Enums\MediaRootKind;
use App\Exceptions\SeriesOperationException;
use App\Models\EpisodeRenameOperation;
use App\Models\MediaFile;
use App\Models\SeriesEpisode;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\SecurityAudit;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RenameEpisode
{
    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private PreviewEpisodeRename $preview,
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private CacheManager $cacheManager,
    ) {}

    public function execute(SeriesEpisode $episode, User $actor, ?string $customName): EpisodeRenameOperation
    {
        $repository = $this->cacheManager->store('database');

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw $this->failure('episode_rename_unavailable', 'Episode rename is temporarily unavailable.', 503);
        }

        try {
            $result = $repository->getStore()->lock(
                CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME,
                self::LOCK_SECONDS,
            )->block(self::LOCK_WAIT_SECONDS, function () use ($episode, $actor, $customName): EpisodeRenameOperation {
                [$operation, $newlyClaimed] = $this->claim($episode->id, $actor, $customName);

                try {
                    if ($newlyClaimed) {
                        SecurityAudit::episodeRenameConfirmed($operation, $actor);
                    }

                    $this->relocate($operation);
                    $operation = $this->complete($operation->id);
                    SecurityAudit::episodeRenameCompleted($operation, $actor);

                    return $operation;
                } catch (SeriesOperationException $exception) {
                    $this->markFailed($operation, $exception);
                    throw $exception;
                } catch (Throwable $exception) {
                    $failure = $this->failure('episode_rename_failed', 'The episode could not be renamed safely. Retry the same title.', 409, $exception);
                    $this->markFailed($operation, $failure);
                    throw $failure;
                }
            });

            if (! $result instanceof EpisodeRenameOperation) {
                throw $this->failure('episode_rename_failed', 'The episode rename result was invalid.');
            }

            return $result;
        } catch (LockTimeoutException $exception) {
            throw $this->failure('episode_rename_busy', 'Show storage is busy. Please retry.', 503, $exception);
        }
    }

    /** @return array{EpisodeRenameOperation,bool} */
    private function claim(int $episodeId, User $actor, ?string $customName): array
    {
        return DB::transaction(function () use ($episodeId, $actor, $customName): array {
            $episode = SeriesEpisode::query()->whereKey($episodeId)->lockForUpdate()->firstOrFail();
            $episode->load('season.series', 'currentMediaFile.sourceUpload');
            $normalizedName = $this->preview->normalize($customName);
            $authorized = $actor->isAdministrator()
                || $episode->currentMediaFile?->sourceUpload?->user_id === $actor->id
                || $episode->currentMediaFile?->imported_by_user_id === $actor->id;

            if (! $authorized) {
                throw $this->failure(
                    'episode_rename_forbidden',
                    $episode->current_media_file_id === null
                        ? 'Only an administrator may rename a missing episode.'
                        : 'Only this episode\'s owner or an administrator may rename it.',
                    403,
                );
            }

            $preview = $this->preview->execute($episode, $actor, $normalizedName);

            if ($preview['can_rename'] !== true) {
                throw $this->failure('episode_rename_blocked', $preview['blocker'] ?? 'The episode rename is blocked.');
            }

            $existing = EpisodeRenameOperation::query()
                ->where('series_episode_id', $episode->id)->whereNot('status', 'completed')
                ->lockForUpdate()->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $mediaFile = $episode->currentMediaFile;
            $identity = $mediaFile === null ? null : $this->physicalIdentity($mediaFile);
            $operation = EpisodeRenameOperation::query()->create([
                'series_episode_id' => $episode->id,
                'actor_user_id' => $actor->id,
                'source_media_file_id' => $mediaFile?->id,
                'source_upload_id' => $mediaFile?->source_upload_id,
                'old_custom_name' => $episode->custom_name,
                'new_custom_name' => $normalizedName,
                'disk_id' => $mediaFile?->disk_id,
                'source_relative_path' => $mediaFile?->relative_path,
                'destination_relative_path' => $preview['destination_relative_path'],
                'size_bytes' => $mediaFile?->size_bytes,
                'device_id' => $identity['device_id'] ?? null,
                'inode_id' => $identity['inode_id'] ?? null,
                'status' => 'pending',
                'claimed_at' => now(),
            ]);

            return [$operation, true];
        }, attempts: 3);
    }

    /** @return array{device_id:int,inode_id:int} */
    private function physicalIdentity(MediaFile $mediaFile): array
    {
        $disk = $this->guardedDisk($mediaFile->disk_id);
        $path = $this->pathGuard->resolveChild($disk->root, $mediaFile->relative_path);
        $deviceId = $this->filesystem->deviceId($path);
        $inodeId = $this->filesystem->inodeId($path);

        if ($deviceId === null || $inodeId === null
            || ! $this->matches($path, $mediaFile->size_bytes, $deviceId, $inodeId)
            || $deviceId !== $this->filesystem->deviceId($disk->root)
        ) {
            throw $this->failure('episode_rename_source_changed', 'The exact tracked episode file is missing or changed.');
        }

        return ['device_id' => $deviceId, 'inode_id' => $inodeId];
    }

    private function relocate(EpisodeRenameOperation $operation): void
    {
        if ($operation->source_media_file_id === null) {
            return;
        }

        if ($operation->disk_id === null || $operation->source_relative_path === null
            || $operation->destination_relative_path === null || $operation->size_bytes === null
            || $operation->device_id === null || $operation->inode_id === null
        ) {
            throw $this->failure('episode_rename_claim_invalid', 'The persisted episode rename claim is incomplete.');
        }

        $disk = $this->guardedDisk($operation->disk_id);
        $source = $this->pathGuard->resolveChild($disk->root, $operation->source_relative_path);
        $destination = $this->pathGuard->resolveChild($disk->root, $operation->destination_relative_path);

        if ($source === $destination) {
            if (! $this->matches($source, $operation->size_bytes, $operation->device_id, $operation->inode_id)) {
                throw $this->failure('episode_rename_claim_changed', 'The file pinned by the rename claim changed.');
            }

            return;
        }

        $sourceExists = $this->filesystem->pathExists($source);
        $destinationExists = $this->filesystem->pathExists($destination);
        $sourceMatches = $sourceExists && $this->matches($source, $operation->size_bytes, $operation->device_id, $operation->inode_id);
        $destinationMatches = $destinationExists && $this->matches($destination, $operation->size_bytes, $operation->device_id, $operation->inode_id);

        if (($sourceExists && ! $sourceMatches) || ($destinationExists && ! $destinationMatches) || (! $sourceMatches && ! $destinationMatches)) {
            throw $this->failure('episode_rename_claim_changed', 'A path pinned by the rename claim is missing, occupied, or changed.');
        }

        if (! $destinationMatches) {
            $this->createParentDirectories($disk->root, dirname($operation->destination_relative_path));

            if (! $this->filesystem->createHardLinkExclusively($source, $destination)
                || ! $this->matches($destination, $operation->size_bytes, $operation->device_id, $operation->inode_id)
            ) {
                throw $this->failure('episode_rename_link_failed', 'The canonical episode file could not be created safely.');
            }
        }

        if ($this->filesystem->pathExists($source)
            && (! $this->matches($source, $operation->size_bytes, $operation->device_id, $operation->inode_id)
                || ! $this->filesystem->deleteFile($source))
        ) {
            throw $this->failure('episode_rename_unlink_failed', 'The original episode path could not be released safely.');
        }

        $this->pruneEmptyAncestors($disk->root, dirname($operation->source_relative_path));
    }

    private function complete(int $operationId): EpisodeRenameOperation
    {
        return DB::transaction(function () use ($operationId): EpisodeRenameOperation {
            $operation = EpisodeRenameOperation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();

            if ($operation->status === 'completed') {
                return $operation;
            }

            $episode = SeriesEpisode::query()->whereKey($operation->series_episode_id)->lockForUpdate()->firstOrFail();

            if ($episode->current_media_file_id !== $operation->source_media_file_id
                || $episode->custom_name !== $operation->old_custom_name
            ) {
                throw $this->failure('episode_rename_stale_claim', 'The episode no longer matches the persisted rename claim.');
            }

            if ($operation->source_media_file_id !== null) {
                $source = MediaFile::query()->whereKey($operation->source_media_file_id)->lockForUpdate()->firstOrFail();
                $samePath = $operation->source_relative_path === $operation->destination_relative_path;

                if ($samePath) {
                    $source->update(['replaced_at' => now(), 'removal_reason' => 'episode_renamed']);
                }

                $newFile = MediaFile::query()->create([
                    'series_episode_id' => $episode->id,
                    'source_upload_id' => null,
                    'imported_by_user_id' => $source->source_upload_id === null
                        ? $source->imported_by_user_id
                        : Upload::query()->whereKey($source->source_upload_id)->value('user_id'),
                    'import_provenance' => [
                        'type' => 'episode_rename',
                        'episode_rename_operation_id' => $operation->id,
                        'previous_media_file_id' => $source->id,
                        'source_upload_id' => $source->source_upload_id,
                        'relocation_proof' => [
                            'type' => 'inode',
                            'size_bytes' => $operation->size_bytes,
                            'device_id' => $operation->device_id,
                            'inode_id' => $operation->inode_id,
                        ],
                    ],
                    'disk_id' => $source->disk_id,
                    'root_kind' => MediaRootKind::Series,
                    'relative_path' => $operation->destination_relative_path,
                    'size_bytes' => $source->size_bytes,
                    'container' => $source->container,
                    'duration_milliseconds' => $source->duration_milliseconds,
                    'video_metadata' => $source->video_metadata,
                    'audio_metadata' => $source->audio_metadata,
                    'probe_snapshot' => $source->probe_snapshot,
                    'finalized_at' => $source->finalized_at,
                ]);

                if (! $samePath) {
                    $source->update(['replaced_at' => now(), 'removal_reason' => 'episode_renamed']);
                }

                $source->update(['replaced_by_media_file_id' => $newFile->id]);
                $episode->update(['custom_name' => $operation->new_custom_name, 'current_media_file_id' => $newFile->id]);
            } else {
                $episode->update(['custom_name' => $operation->new_custom_name]);
            }

            $operation->update([
                'status' => 'completed', 'error_code' => null, 'error_detail' => null,
                'completed_at' => now(), 'failed_at' => null,
            ]);

            return $operation->refresh();
        }, attempts: 3);
    }

    private function createParentDirectories(string $root, string $relativeDirectory): void
    {
        $current = '';

        foreach (explode('/', $relativeDirectory) as $segment) {
            $current = $current === '' ? $segment : $current.'/'.$segment;
            $path = $this->pathGuard->resolveChild($root, $current);

            if (! $this->filesystem->pathExists($path) && ! $this->filesystem->createDirectory($path)) {
                throw $this->failure('episode_rename_directory_failed', 'The canonical episode directory could not be created.');
            }

            if (! $this->filesystem->isDirectory($path) || $this->filesystem->isSymbolicLink($path)) {
                throw $this->failure('episode_rename_directory_unsafe', 'A canonical episode directory is unsafe.');
            }
        }
    }

    private function pruneEmptyAncestors(string $root, string $relativeDirectory): void
    {
        $resolvedRoot = $this->pathGuard->resolveRoot($root);
        $directory = $this->pathGuard->resolveChild($root, $relativeDirectory);

        while ($directory !== $resolvedRoot && str_starts_with($directory, $resolvedRoot.'/')) {
            if (! $this->filesystem->isDirectoryEmpty($directory) || ! $this->filesystem->removeDirectoryIfEmpty($directory)) {
                break;
            }

            $directory = dirname($directory);
        }
    }

    private function matches(string $path, int $size, ?int $deviceId, ?int $inodeId): bool
    {
        return $deviceId !== null && $inodeId !== null
            && ! $this->filesystem->isSymbolicLink($path)
            && $this->filesystem->isRegularFile($path)
            && $this->filesystem->fileSize($path) === $size
            && $this->filesystem->deviceId($path) === $deviceId
            && $this->filesystem->inodeId($path) === $inodeId;
    }

    private function guardedDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->findRoot($diskId, MediaRootKind::Series);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw $this->failure('episode_rename_disk_unavailable', 'The Show disk is unavailable or unhealthy.');
        }

        return $disk;
    }

    private function markFailed(EpisodeRenameOperation $operation, SeriesOperationException $exception): void
    {
        EpisodeRenameOperation::query()->whereKey($operation->id)->whereNot('status', 'completed')->update([
            'status' => 'failed', 'error_code' => $exception->errorCode,
            'error_detail' => $exception->getMessage(), 'failed_at' => now(),
        ]);
    }

    private function failure(string $code, string $message, int $status = 409, ?Throwable $previous = null): SeriesOperationException
    {
        return new SeriesOperationException($code, $message, $status, $previous);
    }
}
