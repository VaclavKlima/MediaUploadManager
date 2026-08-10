<?php

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\LibraryRelocationMatcher;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\RecursiveMovieLibraryScanner;
use App\Support\Tmdb\Exceptions\MovieLookupException;
use App\Support\Tmdb\TmdbClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScanMovieLibrary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [5, 30, 90];

    public function __construct(public readonly int $scanId) {}

    /**
     * Execute the job.
     */
    public function handle(
        ConfiguredDiskRegistry $diskRegistry,
        MediaDiskHealthChecker $healthChecker,
        RecursiveMovieLibraryScanner $scanner,
        MediaPathGuard $pathGuard,
        MediaFilesystem $filesystem,
        TmdbClient $tmdb,
        JellyfinMoviePathBuilder $pathBuilder,
        LibraryRelocationMatcher $relocationMatcher,
    ): void {
        $scan = LibraryScan::query()->findOrFail($this->scanId);

        if ($scan->status === 'completed') {
            return;
        }

        $scan->update(['status' => 'scanning', 'started_at' => $scan->started_at ?? now(), 'error_detail' => null]);
        $diskStatuses = [];
        $discoveredCount = 0;
        $missingCount = 0;

        foreach ($diskRegistry->all() as $disk) {
            $health = $healthChecker->check($disk, $diskRegistry->requiresMountpoint());
            $diskStatuses[] = $health->toArray();

            if (! $health->healthy) {
                continue;
            }

            $files = $scanner->scan($disk);
            $visiblePaths = array_fill_keys(array_column($files, 'relative_path'), true);
            $trackedByPath = MediaFile::query()
                ->where('disk_id', $disk->id)
                ->whereNotNull('active_path_key')
                ->get()
                ->keyBy('relative_path');

            foreach ($files as $file) {
                $tracked = $trackedByPath->get($file['relative_path']);

                if ($tracked instanceof MediaFile) {
                    LibraryFinding::query()
                        ->where('kind', 'missing')
                        ->where('media_file_id', $tracked->id)
                        ->whereNull('resolved_at')
                        ->update([
                            'status' => 'resolved',
                            'resolution' => 'restored',
                            'resolved_at' => now(),
                            'error_detail' => null,
                        ]);

                    continue;
                }

                $identity = $this->resolveIdentity($file['relative_path'], $tmdb);
                $existingMediaItem = $identity['tmdb_id'] === null
                    ? null
                    : MediaItem::query()->where('tmdb_id', $identity['tmdb_id'])->first();
                $status = $identity['status'];
                $destination = null;

                if ($identity['snapshot'] !== null) {
                    $pathMovie = $existingMediaItem ?? new MediaItem($identity['snapshot']);
                    $destination = $pathBuilder->build($pathMovie, $file['source_filename'])->relativePath;
                }

                if ($existingMediaItem !== null) {
                    if ($existingMediaItem->current_media_file_id !== null
                        || MediaFile::query()->where('media_item_id', $existingMediaItem->id)->whereNotNull('active_path_key')->exists()
                        || Upload::query()->where('media_item_id', $existingMediaItem->id)
                            ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                            ->exists()
                    ) {
                        $status = 'conflict';
                    }
                }

                LibraryFinding::query()->updateOrCreate(
                    [
                        'library_scan_id' => $scan->id,
                        'path_key' => LibraryFinding::pathKey($disk->id, $file['relative_path']),
                        'disk_id' => $disk->id,
                        'relative_path' => $file['relative_path'],
                    ],
                    [
                        ...$file,
                        'media_item_id' => $existingMediaItem?->id,
                        'kind' => 'discovered',
                        'status' => $status,
                        'identity_source' => $identity['source'],
                        'identity_snapshot' => $identity['snapshot'],
                        'tmdb_id' => $identity['tmdb_id'],
                        'imdb_id' => $identity['imdb_id'],
                        'destination_relative_path' => $destination,
                        'error_detail' => $identity['error'],
                    ],
                );
                $discoveredCount++;
            }

            foreach ($trackedByPath as $relativePath => $tracked) {
                if (isset($visiblePaths[$relativePath])) {
                    continue;
                }

                try {
                    $trackedPath = $pathGuard->resolveChild($disk->root, $relativePath);

                    if ($filesystem->pathExists($trackedPath)) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }

                LibraryFinding::query()->updateOrCreate(
                    [
                        'library_scan_id' => $scan->id,
                        'path_key' => LibraryFinding::pathKey($disk->id, $relativePath),
                        'disk_id' => $disk->id,
                        'relative_path' => $relativePath,
                    ],
                    [
                        'media_item_id' => $tracked->media_item_id,
                        'media_file_id' => $tracked->id,
                        'source_folder' => dirname($relativePath) === '.' ? '' : dirname($relativePath),
                        'source_filename' => basename($relativePath),
                        'size_bytes' => $tracked->size_bytes,
                        'kind' => 'missing',
                        'status' => 'missing',
                    ],
                );
                $missingCount++;
            }
        }

        $duplicateTmdbIds = LibraryFinding::query()
            ->where('library_scan_id', $scan->id)
            ->where('kind', 'discovered')
            ->whereNotNull('tmdb_id')
            ->select('tmdb_id')
            ->groupBy('tmdb_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tmdb_id');

        if ($duplicateTmdbIds->isNotEmpty()) {
            LibraryFinding::query()
                ->where('library_scan_id', $scan->id)
                ->whereIn('tmdb_id', $duplicateTmdbIds)
                ->whereNull('resolved_at')
                ->update([
                    'status' => 'conflict',
                    'error_detail' => 'Multiple discovered files identify the same movie; multiple versions are not supported.',
                ]);
        }

        $relocationMatcher->matchScan($scan);

        $scan->update([
            'status' => 'completed',
            'disk_statuses' => $diskStatuses,
            'discovered_count' => $discoveredCount,
            'missing_count' => $missingCount,
            'completed_at' => now(),
        ]);

        LibraryFinding::query()
            ->whereNotNull('resolved_at')
            ->whereIn('resolution', ['imported', 'deleted', 'relocated'])
            ->where('source_folder', '!=', '')
            ->select('id')
            ->eachById(function (LibraryFinding $finding) use ($scan): void {
                CleanupResolvedLibraryFindingFolder::dispatch($finding->id, $scan->user_id);
            });
    }

    public function failed(?Throwable $exception): void
    {
        LibraryScan::query()->whereKey($this->scanId)->update([
            'status' => 'failed',
            'error_detail' => $exception?->getMessage() ?? 'The library scan failed.',
            'completed_at' => now(),
        ]);
    }

    /** @return array{status: string, source: string|null, tmdb_id: int|null, imdb_id: string|null, snapshot: array<string, mixed>|null, error: string|null} */
    private function resolveIdentity(string $relativePath, TmdbClient $tmdb): array
    {
        preg_match_all('/\[tmdbid-(\d+)\]/i', $relativePath, $tmdbMatches);
        preg_match_all('/\[imdbid-(tt\d{7,12})\]/i', $relativePath, $imdbMatches);
        $tmdbIds = array_values(array_unique(array_map(
            fn (string $id): int => (int) $id,
            $tmdbMatches[1],
        )));
        $imdbIds = array_values(array_unique(array_map(
            fn (string $id): string => strtolower($id),
            $imdbMatches[1],
        )));

        if (count($tmdbIds) > 1 || count($imdbIds) > 1) {
            return ['status' => 'conflict', 'source' => 'tags', 'tmdb_id' => null, 'imdb_id' => null, 'snapshot' => null, 'error' => 'Multiple distinct identity tags were found.'];
        }

        if ($tmdbIds === [] && $imdbIds === []) {
            return ['status' => 'needs_identification', 'source' => null, 'tmdb_id' => null, 'imdb_id' => null, 'snapshot' => null, 'error' => null];
        }

        try {
            $fromTmdb = $tmdbIds === [] ? null : $tmdb->movie($tmdbIds[0]);
            $fromImdb = $imdbIds === [] ? null : $tmdb->findByImdb($imdbIds[0]);
        } catch (MovieLookupException $exception) {
            return ['status' => 'needs_identification', 'source' => 'tags', 'tmdb_id' => null, 'imdb_id' => $imdbIds[0] ?? null, 'snapshot' => null, 'error' => $exception->getMessage()];
        }

        if ($fromTmdb !== null && $fromImdb !== null && $fromTmdb->tmdbId !== $fromImdb->tmdbId) {
            return ['status' => 'conflict', 'source' => 'tags', 'tmdb_id' => $fromTmdb->tmdbId, 'imdb_id' => $imdbIds[0], 'snapshot' => null, 'error' => 'TMDB and IMDb tags identify different movies.'];
        }

        $details = $fromTmdb ?? $fromImdb;

        if ($details === null) {
            throw new \LogicException('An identity tag lookup completed without movie details.');
        }

        return [
            'status' => 'ready',
            'source' => $fromTmdb !== null && $fromImdb !== null ? 'agreeing_tags' : ($fromTmdb !== null ? 'tmdb_tag' : 'imdb_tag'),
            'tmdb_id' => $details->tmdbId,
            'imdb_id' => $details->imdbId ?? ($imdbIds[0] ?? null),
            'snapshot' => $details->mediaItemSnapshot(),
            'error' => null,
        ];
    }
}
