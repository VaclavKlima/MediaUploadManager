<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\MediaItemReidentification;
use App\Models\Upload;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Tmdb\Data\MovieDetails;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final readonly class PreviewMovieReidentification
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private JellyfinMoviePathBuilder $pathBuilder,
    ) {}

    /** @return array<string, mixed> */
    public function execute(MediaItem $mediaItem, MovieDetails $details): array
    {
        $mediaItem->loadMissing('currentMediaFile');
        $currentFile = $mediaItem->currentMediaFile;
        $operation = $this->incompleteOperation($mediaItem);
        $newSnapshot = $operation->new_metadata_snapshot ?? $details->mediaItemSnapshot();
        $proposedIdentity = new MediaItem($newSnapshot);
        $destinationRelativePath = null;
        $blocker = $this->databaseBlocker($mediaItem, $details, $newSnapshot, $operation);

        if ($blocker === null && $currentFile !== null) {
            try {
                $destinationRelativePath = $operation->destination_relative_path
                    ?? $this->pathBuilder->build($proposedIdentity, basename($currentFile->relative_path))->relativePath;
                $blocker = $this->physicalBlocker(
                    $mediaItem,
                    $currentFile,
                    $destinationRelativePath,
                    $operation,
                );
            } catch (Throwable) {
                $blocker = $this->blocker('invalid_destination', 'The selected identity cannot produce a safe canonical path.');
            }
        }

        return [
            'current_identity' => $this->identity($mediaItem),
            'proposed_identity' => $this->identity($proposedIdentity),
            'current_relative_path' => $currentFile?->relative_path,
            'proposed_relative_path' => $destinationRelativePath,
            'disk' => $currentFile === null ? null : [
                'id' => $currentFile->disk_id,
                'label' => $this->diskRegistry->find($currentFile->disk_id)?->label,
            ],
            'size_bytes' => $currentFile?->size_bytes,
            'eligible' => $blocker === null,
            'blocker' => $blocker,
            'retry' => $operation === null ? null : [
                'operation_id' => $operation->id,
                'status' => $operation->status,
                'error_code' => $operation->error_code,
                'error_detail' => $operation->error_detail,
            ],
        ];
    }

    private function incompleteOperation(MediaItem $mediaItem): ?MediaItemReidentification
    {
        return MediaItemReidentification::query()
            ->whereBelongsTo($mediaItem)
            ->whereNull('completed_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $newSnapshot
     * @return array{code: string, message: string}|null
     */
    private function databaseBlocker(
        MediaItem $mediaItem,
        MovieDetails $details,
        array $newSnapshot,
        ?MediaItemReidentification $operation,
    ): ?array {
        if ($mediaItem->deletion_claim !== null || $mediaItem->deletion_requested_at !== null) {
            return $this->blocker('deletion_claimed', 'A movie being deleted cannot be re-identified.');
        }

        if ($operation !== null && ($newSnapshot['tmdb_id'] ?? null) !== $details->tmdbId) {
            return $this->blocker('operation_target_mismatch', 'Retry must use the identity already pinned by the failed operation.');
        }

        if ($operation === null && $mediaItem->tmdb_id === $details->tmdbId) {
            return $this->blocker('identity_unchanged', 'Choose a different movie identity.');
        }

        if (Upload::query()->whereBelongsTo($mediaItem)->where('status', UploadStatus::Failed)->exists()) {
            return $this->blocker('failed_upload', 'Discard or successfully retry every failed upload first.');
        }

        if (Upload::query()->whereBelongsTo($mediaItem)->whereIn('status', UploadStatus::capacityReservingValues())->exists()) {
            return $this->blocker('active_upload', 'Finish or cancel every active upload first.');
        }

        if ($mediaItem->current_media_file_id === null
            && MediaFile::query()->whereBelongsTo($mediaItem)->whereNotNull('active_path_key')->exists()
        ) {
            return $this->blocker('stale_primary', 'The movie has an active file record without a current primary.');
        }

        $targets = $this->targetIdentities($mediaItem, $newSnapshot);

        if ($targets->count() > 1
            || $targets->contains(fn (MediaItem $target): bool => ! $this->isEmptyPlaceholder($target))
        ) {
            return $this->blocker('identity_conflict', 'The selected movie identity is already tracked.');
        }

        return null;
    }

    /** @return array{code: string, message: string}|null */
    private function physicalBlocker(
        MediaItem $mediaItem,
        MediaFile $currentFile,
        string $destinationRelativePath,
        ?MediaItemReidentification $operation,
    ): ?array {
        if ($currentFile->media_item_id !== $mediaItem->id
            || $currentFile->removed_at !== null
            || $currentFile->replaced_at !== null
            || $currentFile->active_path_key !== MediaFile::activePathKey($currentFile->disk_id, $currentFile->relative_path)
        ) {
            return $this->blocker('stale_primary', 'The current primary no longer matches its active file record.');
        }

        $disk = $this->diskRegistry->find($currentFile->disk_id);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            return $this->blocker('disk_unavailable', 'The current movie disk is unavailable or unhealthy.');
        }

        try {
            $source = $this->pathGuard->resolveChild($disk->root, $currentFile->relative_path);
            $destination = $this->pathGuard->resolveChild($disk->root, $destinationRelativePath);
        } catch (Throwable) {
            return $this->blocker('unsafe_path', 'The tracked source or proposed destination is unsafe.');
        }

        $sourceExists = $this->filesystem->pathExists($source);
        $destinationExists = $this->filesystem->pathExists($destination);

        if ($operation === null) {
            if (! $sourceExists || ! $this->matchesFile($source, $currentFile->size_bytes)) {
                return $this->blocker('source_changed', 'The tracked source file is missing, changed, or symbolic.');
            }

            if ($destinationExists || $this->occupiedDirectory(dirname($destination))) {
                return $this->blocker('destination_occupied', 'The proposed canonical destination is already occupied.');
            }

            return null;
        }

        if ($operation->source_media_file_id !== $currentFile->id
            || $operation->disk_id !== $currentFile->disk_id
            || $operation->source_relative_path !== $currentFile->relative_path
            || $operation->destination_relative_path !== $destinationRelativePath
        ) {
            return $this->blocker('stale_claim', 'The persisted re-identification claim no longer matches the current primary.');
        }

        $sourceMatches = $sourceExists && $this->matchesClaim($source, $operation);
        $destinationMatches = $destinationExists && $this->matchesClaim($destination, $operation);

        if (($sourceExists && ! $sourceMatches) || ($destinationExists && ! $destinationMatches)) {
            return $this->blocker('claimed_file_changed', 'A path pinned by the re-identification claim has changed.');
        }

        if (! $sourceMatches && ! $destinationMatches) {
            return $this->blocker('claimed_file_missing', 'The file pinned by the re-identification claim is missing.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return Collection<int, MediaItem>
     */
    private function targetIdentities(MediaItem $source, array $snapshot): Collection
    {
        return MediaItem::query()
            ->whereKeyNot($source->id)
            ->where(function ($query) use ($snapshot): void {
                $query->where('tmdb_id', $snapshot['tmdb_id']);

                if (is_string($snapshot['imdb_id'] ?? null)) {
                    $query->orWhere('imdb_id', $snapshot['imdb_id']);
                }
            })
            ->get();
    }

    private function isEmptyPlaceholder(MediaItem $mediaItem): bool
    {
        return $mediaItem->current_media_file_id === null
            && $mediaItem->deletion_claim === null
            && $mediaItem->deletion_requested_at === null
            && ! $mediaItem->uploads()->exists()
            && ! $mediaItem->mediaFiles()->exists()
            && ! $mediaItem->reidentifications()->exists();
    }

    private function matchesFile(string $path, int $size): bool
    {
        return $this->filesystem->isRegularFile($path)
            && $this->filesystem->fileSize($path) === $size;
    }

    private function matchesClaim(string $path, MediaItemReidentification $operation): bool
    {
        return $this->matchesFile($path, (int) $operation->size_bytes)
            && $this->filesystem->deviceId($path) === $operation->device_id
            && $this->filesystem->inodeId($path) === $operation->inode_id;
    }

    private function occupiedDirectory(string $directory): bool
    {
        return $this->filesystem->pathExists($directory)
            && (! $this->filesystem->isDirectory($directory)
                || $this->filesystem->isSymbolicLink($directory)
                || ! $this->filesystem->isDirectoryEmpty($directory));
    }

    /** @return array<string, int|string|null> */
    private function identity(MediaItem $mediaItem): array
    {
        return [
            'tmdb_id' => $mediaItem->tmdb_id,
            'imdb_id' => $mediaItem->imdb_id,
            'title' => $mediaItem->title,
            'original_title' => $mediaItem->original_title,
            'release_year' => $mediaItem->release_year,
        ];
    }

    /** @return array{code: string, message: string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
