<?php

namespace App\Support\Media;

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Contracts\MountPointChecker;
use Illuminate\Support\Collection;
use Throwable;

final readonly class MovieUploadConflictChecker
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private MountPointChecker $mountPointChecker,
    ) {}

    public function check(
        MediaItem $mediaItem,
        CanonicalMoviePath $path,
        ?User $actor = null,
        ?int $ignoredUploadId = null,
    ): MovieUploadConflictReport {
        $disks = $this->diskRegistry->all();
        usort($disks, fn (ConfiguredMediaDisk $left, ConfiguredMediaDisk $right): int => $left->id <=> $right->id);

        /** @var array<string, ConfiguredMediaDisk> $configuredDisks */
        $configuredDisks = [];
        /** @var array<string, list<MovieConflictBlocker>> $localReasons */
        $localReasons = [];

        foreach ($disks as $disk) {
            $configuredDisks[$disk->id] = $disk;
            $localReasons[$disk->id] = [];
        }

        /** @var array<string, MovieConflictBlocker> $globalBlockers */
        $globalBlockers = [];
        /** @var array<string, MovieConflictBlocker> $replacementBlockers */
        $replacementBlockers = [];

        if ($mediaItem->deletion_requested_at !== null || $mediaItem->deletion_claim !== null) {
            $this->addSharedBlocker(
                $globalBlockers,
                $replacementBlockers,
                $localReasons,
                $configuredDisks,
                'movie_deletion_in_progress',
                'This movie has a confirmed permanent deletion in progress.',
                null,
            );
        }

        $currentMediaFile = $mediaItem->current_media_file_id === null
            ? null
            : $mediaItem->currentMediaFile()->with('sourceUpload')->first();
        $replaceable = $this->replaceableMediaFile($mediaItem, $currentMediaFile, $configuredDisks, $actor);

        if ($mediaItem->current_media_file_id !== null) {
            $this->addBlocker(
                $globalBlockers,
                $localReasons,
                $configuredDisks,
                'current_primary_exists',
                'A current primary already exists for this movie.',
                $currentMediaFile?->disk_id,
            );
        }

        $liveMediaFiles = $mediaItem->mediaFiles()
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->oldest('id')
            ->get();

        foreach ($liveMediaFiles as $mediaFile) {
            if ($mediaFile->getKey() === $mediaItem->current_media_file_id) {
                continue;
            }

            $this->addSharedBlocker(
                $globalBlockers,
                $replacementBlockers,
                $localReasons,
                $configuredDisks,
                'media_file_exists',
                'A live media file already exists for this movie.',
                $mediaFile->disk_id,
            );
        }

        $uploads = $mediaItem->uploads()
            ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
            ->with('mediaFile')
            ->oldest('id')
            ->get();

        foreach ($uploads as $upload) {
            if ($upload->getKey() === $ignoredUploadId) {
                continue;
            }

            if ($upload->status === UploadStatus::Completed && $upload->mediaFile !== null) {
                continue;
            }

            [$code, $message] = $this->uploadBlocker($upload);
            $this->addSharedBlocker(
                $globalBlockers,
                $replacementBlockers,
                $localReasons,
                $configuredDisks,
                $code,
                $message,
                $upload->disk_id,
            );
        }

        $diskStatuses = [];

        foreach ($disks as $disk) {
            $filesystemUnavailable = false;
            $hasLocalConflict = $this->hasUnattributedLocalConflict($localReasons[$disk->id], $replaceable);

            try {
                $resolvedRoot = $this->checkedRoot($disk);
                $directory = $this->pathGuard->resolveChild($disk->root, $path->directory);
                $file = $this->pathGuard->resolveChild($disk->root, $path->relativePath);
                $attributedDirectory = $replaceable !== null
                    && $replaceable->diskId === $disk->id
                    && dirname($replaceable->relativePath) === $path->directory;
                $attributedFile = $replaceable !== null
                    && $replaceable->diskId === $disk->id
                    && $replaceable->relativePath === $path->relativePath;

                if ($this->filesystem->pathExists($directory) && ! $attributedDirectory) {
                    $hasLocalConflict = true;
                    $this->addSharedBlocker(
                        $globalBlockers,
                        $replacementBlockers,
                        $localReasons,
                        $configuredDisks,
                        'target_directory_exists',
                        'The canonical movie directory already exists on this disk.',
                        $disk->id,
                    );
                }

                if ($this->filesystem->pathExists($file) && ! $attributedFile) {
                    $hasLocalConflict = true;
                    $this->addSharedBlocker(
                        $globalBlockers,
                        $replacementBlockers,
                        $localReasons,
                        $configuredDisks,
                        'target_file_exists',
                        'The canonical movie file already exists on this disk.',
                        $disk->id,
                    );
                }

                if (! $this->filesystem->isReadable($resolvedRoot)) {
                    $filesystemUnavailable = true;
                }
            } catch (Throwable) {
                $filesystemUnavailable = true;
            }

            if ($filesystemUnavailable) {
                $localReasons[$disk->id][] = new MovieConflictBlocker(
                    'disk_unavailable',
                    'The disk target could not be checked safely.',
                );
            }

            $replacementAllowed = $replaceable !== null && $replacementBlockers === [];
            $status = match (true) {
                $hasLocalConflict => 'conflict',
                $filesystemUnavailable => 'unavailable',
                $replacementAllowed => 'replaceable',
                default => 'clear',
            };

            $diskStatuses[] = new MovieDiskTargetStatus(
                id: $disk->id,
                label: $disk->label,
                status: $status,
                reasons: $this->uniqueReasons($localReasons[$disk->id]),
            );
        }

        if ($replacementBlockers !== []) {
            $diskStatuses = array_map(
                fn (MovieDiskTargetStatus $disk): MovieDiskTargetStatus => $disk->status !== 'replaceable'
                    ? $disk
                    : new MovieDiskTargetStatus(
                        id: $disk->id,
                        label: $disk->label,
                        status: $disk->reasons === [] ? 'clear' : 'conflict',
                        reasons: $disk->reasons,
                    ),
                $diskStatuses,
            );
        }

        $hasClearDisk = collect($diskStatuses)->contains(
            fn (MovieDiskTargetStatus $disk): bool => $disk->status === 'clear',
        );
        $hasReplaceableDisk = collect($diskStatuses)->contains(
            fn (MovieDiskTargetStatus $disk): bool => $disk->status === 'replaceable',
        );

        if ($globalBlockers === [] && ! $hasClearDisk) {
            $globalBlockers['no_clear_disk|'] = new MovieConflictBlocker(
                'no_clear_disk',
                'No configured disk target is currently clear.',
            );
        }

        $canReplaceCurrentPrimary = $replaceable !== null
            && $replacementBlockers === []
            && $hasReplaceableDisk;

        return new MovieUploadConflictReport(
            canStartNewUpload: $globalBlockers === [],
            canReplaceCurrentPrimary: $canReplaceCurrentPrimary,
            replaceable: $canReplaceCurrentPrimary ? $replaceable : null,
            blockers: array_values($globalBlockers),
            disks: $diskStatuses,
        );
    }

    /** @param array<string, ConfiguredMediaDisk> $configuredDisks */
    private function replaceableMediaFile(
        MediaItem $mediaItem,
        ?MediaFile $currentMediaFile,
        array $configuredDisks,
        ?User $actor,
    ): ?ReplaceableMediaFile {
        if ($actor === null
            || $currentMediaFile === null
            || $currentMediaFile->replaced_at !== null
            || $currentMediaFile->removed_at !== null
            || $currentMediaFile->replaced_by_media_file_id !== null
        ) {
            return null;
        }

        $sourceUpload = $currentMediaFile->sourceUpload;
        $disk = $configuredDisks[$currentMediaFile->disk_id] ?? null;

        if ($sourceUpload === null
            || $sourceUpload->status !== UploadStatus::Completed
            || ($sourceUpload->user_id !== $actor->getKey() && ! $actor->isAdministrator())
            || $sourceUpload->media_item_id !== $mediaItem->getKey()
            || $sourceUpload->disk_id !== $currentMediaFile->disk_id
            || $sourceUpload->target_relative_path !== $currentMediaFile->relative_path
            || $sourceUpload->declared_size !== $currentMediaFile->size_bytes
            || $sourceUpload->confirmed_offset !== $currentMediaFile->size_bytes
            || $disk === null
        ) {
            return null;
        }

        try {
            $this->checkedRoot($disk);
            $path = $this->pathGuard->resolveChild($disk->root, $currentMediaFile->relative_path);

            if ($this->filesystem->isSymbolicLink($path)
                || ! $this->filesystem->isRegularFile($path)
                || $this->filesystem->fileSize($path) !== $currentMediaFile->size_bytes
            ) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $finalizedAt = $currentMediaFile->finalized_at->toISOString();

        if (! is_string($finalizedAt)) {
            return null;
        }

        return new ReplaceableMediaFile(
            id: $currentMediaFile->id,
            sourceUploadId: $currentMediaFile->source_upload_id,
            diskId: $currentMediaFile->disk_id,
            diskLabel: $disk->label,
            relativePath: $currentMediaFile->relative_path,
            sizeBytes: $currentMediaFile->size_bytes,
            finalizedAt: $finalizedAt,
        );
    }

    private function checkedRoot(ConfiguredMediaDisk $disk): string
    {
        $resolvedRoot = $this->pathGuard->resolveRoot($disk->root);
        $mountInspection = $this->diskRegistry->requiresMountpoint()
            ? $this->mountPointChecker->inspect($resolvedRoot)
            : null;
        $markerPath = $this->pathGuard->resolveChild($disk->root, '.media-upload-manager/disk.json');
        $markerContents = $this->filesystem->readFile($markerPath);
        $marker = $markerContents === null ? null : DiskMarker::parse($markerContents);

        if (($mountInspection !== null && (! $mountInspection->available || ! $mountInspection->exactMountPoint))
            || $marker === null
            || $marker['disk_id'] !== $disk->id
        ) {
            throw new \RuntimeException('The configured media disk is unavailable.');
        }

        return $resolvedRoot;
    }

    /** @param list<MovieConflictBlocker> $reasons */
    private function hasUnattributedLocalConflict(array $reasons, ?ReplaceableMediaFile $replaceable): bool
    {
        return collect($reasons)->contains(
            fn (MovieConflictBlocker $reason): bool => $replaceable === null || $reason->code !== 'current_primary_exists',
        );
    }

    /**
     * @param  array<string, MovieConflictBlocker>  $globalBlockers
     * @param  array<string, MovieConflictBlocker>  $replacementBlockers
     * @param  array<string, list<MovieConflictBlocker>>  $localReasons
     * @param  array<string, ConfiguredMediaDisk>  $configuredDisks
     */
    private function addSharedBlocker(
        array &$globalBlockers,
        array &$replacementBlockers,
        array &$localReasons,
        array $configuredDisks,
        string $code,
        string $message,
        ?string $diskId,
    ): void {
        $this->addBlocker($globalBlockers, $localReasons, $configuredDisks, $code, $message, $diskId);
        $disk = $diskId === null ? null : ($configuredDisks[$diskId] ?? null);
        $replacementBlockers[$code.'|'.($disk->id ?? '')] = new MovieConflictBlocker(
            $code,
            $message,
            $disk?->id,
            $disk?->label,
        );
    }

    /**
     * @param  array<string, MovieConflictBlocker>  $globalBlockers
     * @param  array<string, list<MovieConflictBlocker>>  $localReasons
     * @param  array<string, ConfiguredMediaDisk>  $configuredDisks
     */
    private function addBlocker(
        array &$globalBlockers,
        array &$localReasons,
        array $configuredDisks,
        string $code,
        string $message,
        ?string $diskId,
    ): void {
        $disk = $diskId === null ? null : ($configuredDisks[$diskId] ?? null);
        $blocker = new MovieConflictBlocker($code, $message, $disk?->id, $disk?->label);
        $globalBlockers[$code.'|'.($disk->id ?? '')] = $blocker;

        if ($diskId !== null && array_key_exists($diskId, $localReasons)) {
            $localReasons[$diskId][] = $blocker;
        }
    }

    /** @return array{string, string} */
    private function uploadBlocker(Upload $upload): array
    {
        return match ($upload->status) {
            UploadStatus::Failed => ['retryable_upload_exists', 'A failed upload for this movie remains retryable.'],
            UploadStatus::Completed => ['completed_upload_exists', 'A completed upload already exists for this movie.'],
            default => ['active_upload_exists', 'An active upload already exists for this movie.'],
        };
    }

    /**
     * @param  list<MovieConflictBlocker>  $reasons
     * @return list<MovieConflictBlocker>
     */
    private function uniqueReasons(array $reasons): array
    {
        return array_values(Collection::make($reasons)
            ->unique(fn (MovieConflictBlocker $reason): string => $reason->code)
            ->values()
            ->all());
    }
}
