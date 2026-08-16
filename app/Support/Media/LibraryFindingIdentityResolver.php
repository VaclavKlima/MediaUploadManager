<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\RelocationVerificationException;
use App\Support\Tmdb\TmdbClient;
use RuntimeException;
use Throwable;

final readonly class LibraryFindingIdentityResolver
{
    public function __construct(
        private TmdbClient $tmdb,
        private JellyfinMoviePathBuilder $pathBuilder,
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private LibraryRelocationVerifier $relocationVerifier,
    ) {}

    public function resolve(LibraryFinding $finding, int $tmdbId): LibraryFindingIdentityDecision
    {
        $this->assertIdentifiable($finding);

        $details = $this->tmdb->movie($tmdbId);
        $snapshot = $details->mediaItemSnapshot();
        $destination = $this->pathBuilder->build(new MediaItem($snapshot), $finding->source_filename);
        $existingMediaItem = MediaItem::query()->where('tmdb_id', $details->tmdbId)->first();
        $duplicateFindingIds = array_values(LibraryFinding::query()
            ->where('library_scan_id', $finding->library_scan_id)
            ->where('root_kind', MediaRootKind::Movies)
            ->whereKeyNot($finding->id)
            ->where('kind', 'discovered')
            ->where('tmdb_id', $details->tmdbId)
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (LibraryFinding $duplicate): int => $duplicate->id)
            ->all());
        $operation = 'import';
        $pairedMissing = null;
        $pairedMissingMediaFileId = null;

        if ($existingMediaItem !== null && $duplicateFindingIds === []) {
            $missingCandidates = LibraryFinding::query()
                ->where('library_scan_id', $finding->library_scan_id)
                ->where('kind', 'missing')
                ->where('status', 'missing')
                ->whereNull('resolved_at')
                ->where('media_item_id', $existingMediaItem->id)
                ->get();

            if ($missingCandidates->count() === 1) {
                $candidate = $missingCandidates->first();

                if ($candidate instanceof LibraryFinding && is_int($candidate->media_file_id)) {
                    try {
                        $this->relocationVerifier->prove(
                            $finding,
                            $candidate,
                            $details->tmdbId,
                            $destination->relativePath,
                        );
                        $operation = 'restore';
                        $pairedMissing = $candidate;
                        $pairedMissingMediaFileId = $candidate->media_file_id;
                    } catch (RelocationVerificationException) {
                        $pairedMissing = null;
                        $pairedMissingMediaFileId = null;
                    }
                }
            }
        }

        [$blockerCode, $blockerMessage] = $this->blocker(
            $finding,
            $existingMediaItem,
            $duplicateFindingIds,
            $destination,
            $operation,
        );

        return new LibraryFindingIdentityDecision(
            tmdbId: $details->tmdbId,
            imdbId: $details->imdbId,
            snapshot: $snapshot,
            destinationRelativePath: $destination->relativePath,
            existingMediaItemId: $existingMediaItem?->id,
            duplicateFindingIds: $duplicateFindingIds,
            blockerCode: $blockerCode,
            blockerMessage: $blockerMessage,
            operation: $operation,
            relocation: $pairedMissing === null ? null : [
                'finding_id' => $pairedMissing->id,
                'media_file_id' => $pairedMissingMediaFileId,
                'disk_id' => $pairedMissing->disk_id,
                'relative_path' => $pairedMissing->relative_path,
                'size_bytes' => $pairedMissing->size_bytes,
            ],
        );
    }

    private function assertIdentifiable(LibraryFinding $finding): void
    {
        if ($finding->root_kind !== MediaRootKind::Movies
            || $finding->kind !== 'discovered'
            || $finding->resolved_at !== null
            || $finding->operation_claim !== null
            || ! in_array($finding->status, ['needs_identification', 'conflict', 'ready', 'failed'], true)
        ) {
            throw new RuntimeException('This finding can no longer be identified.');
        }
    }

    /**
     * @param  list<int>  $duplicateFindingIds
     * @return array{string|null, string|null}
     */
    private function blocker(
        LibraryFinding $finding,
        ?MediaItem $existingMediaItem,
        array $duplicateFindingIds,
        CanonicalMoviePath $destination,
        string $operation,
    ): array {
        if ($operation !== 'restore' && $existingMediaItem !== null && $this->hasDatabaseConflict($existingMediaItem)) {
            return ['database_conflict', 'This movie already has a current file or active upload.'];
        }

        if ($duplicateFindingIds !== []) {
            return ['duplicate_finding', 'Another unresolved finding in this scan identifies the same movie.'];
        }

        try {
            $disk = $this->diskRegistry->find($finding->disk_id);

            if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
                return ['disk_unavailable', 'The source disk is unavailable or its marker identity changed.'];
            }

            $sourcePath = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);

            if (! is_int($finding->size_bytes)
                || ! is_int($finding->device_id)
                || ! is_int($finding->inode_id)
                || ! $this->filesystem->isRegularFile($sourcePath)
                || $this->filesystem->fileSize($sourcePath) !== $finding->size_bytes
                || $this->filesystem->deviceId($sourcePath) !== $finding->device_id
                || $this->filesystem->inodeId($sourcePath) !== $finding->inode_id
            ) {
                return ['source_changed', 'The file no longer matches its verified scan snapshot.'];
            }

            if ($this->filesystem->deviceId($disk->root) !== $finding->device_id) {
                return ['source_filesystem_changed', 'The source file is no longer on its configured disk filesystem.'];
            }

            if ($this->sourceIsClaimedByUpload($finding)) {
                return ['source_claimed', 'The source file is claimed by an upload.'];
            }

            $destinationPath = $this->pathGuard->resolveChild($disk->root, $destination->relativePath);

            if ($finding->relative_path !== $destination->relativePath && $this->filesystem->pathExists($destinationPath)) {
                return ['destination_occupied', 'The canonical destination is already occupied.'];
            }

            $directoryBlocker = $this->destinationDirectoryBlocker(
                dirname($destinationPath),
                $sourcePath,
                $destinationPath,
            );

            if ($directoryBlocker !== null) {
                return $directoryBlocker;
            }
        } catch (Throwable) {
            return ['filesystem_unavailable', 'The source and destination could not be checked safely.'];
        }

        return [null, null];
    }

    private function hasDatabaseConflict(MediaItem $mediaItem): bool
    {
        return $mediaItem->current_media_file_id !== null
            || MediaFile::query()->where('media_item_id', $mediaItem->id)->whereNotNull('active_path_key')->exists()
            || Upload::query()->where('media_item_id', $mediaItem->id)
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists();
    }

    private function sourceIsClaimedByUpload(LibraryFinding $finding): bool
    {
        return Upload::query()
            ->where('disk_id', $finding->disk_id)
            ->where(function ($query) use ($finding): void {
                $query->where('target_relative_path', $finding->relative_path)
                    ->orWhere('staging_relative_path', $finding->relative_path);
            })
            ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
            ->exists();
    }

    /** @return array{string, string}|null */
    private function destinationDirectoryBlocker(string $directory, string $sourcePath, string $destinationPath): ?array
    {
        if (! $this->filesystem->pathExists($directory)) {
            return null;
        }

        if (! $this->filesystem->isDirectory($directory) || $this->filesystem->isSymbolicLink($directory)) {
            return ['destination_unsafe', 'The canonical destination directory is unsafe.'];
        }

        $entries = @scandir($directory);

        if (! is_array($entries)) {
            return ['destination_unreadable', 'The canonical destination directory cannot be inspected.'];
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
                return ['destination_symlink', 'The canonical destination directory contains a symbolic link.'];
            }

            if ($this->filesystem->isRegularFile($path)
                && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)
            ) {
                return ['destination_version', 'The canonical destination already contains another movie version.'];
            }
        }

        return null;
    }
}
