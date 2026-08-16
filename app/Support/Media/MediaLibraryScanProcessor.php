<?php

namespace App\Support\Media;

use App\Actions\QueueLibraryFindingImport;
use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Enums\UploadStatus;
use App\Jobs\CleanupResolvedLibraryFindingFolder;
use App\Models\EpisodeRenameOperation;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Series;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Series\JellyfinSeriesPathBuilder;
use App\Support\Tmdb\Exceptions\MovieLookupException;
use App\Support\Tmdb\SeriesTmdbClient;
use App\Support\Tmdb\TmdbClient;
use Throwable;

final readonly class MediaLibraryScanProcessor
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private RecursiveMovieLibraryScanner $scanner,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private TmdbClient $tmdb,
        private SeriesTmdbClient $seriesTmdb,
        private JellyfinMoviePathBuilder $moviePathBuilder,
        private JellyfinSeriesPathBuilder $seriesPathBuilder,
        private LibraryRelocationMatcher $relocationMatcher,
        private QueueLibraryFindingImport $queueImport,
    ) {}

    public function process(int $scanId): void
    {
        $scan = LibraryScan::query()->findOrFail($scanId);

        if ($scan->status === 'completed') {
            return;
        }

        $scan->update(['status' => 'scanning', 'started_at' => $scan->started_at ?? now(), 'error_detail' => null]);
        $diskStatuses = [];
        $discoveredCount = 0;
        $missingCount = 0;

        foreach ($this->diskRegistry->allRoots() as $disk) {
            $health = $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint());
            $diskStatuses[] = [...$health->toRootArray(), 'root_kind' => $disk->kind->value];

            if (! $health->healthy) {
                continue;
            }

            [$rootDiscoveredCount, $rootMissingCount] = $this->scanRoot($scan, $disk);
            $discoveredCount += $rootDiscoveredCount;
            $missingCount += $rootMissingCount;
        }

        $this->markDuplicateMovieIdentities($scan);
        $this->markDuplicateSeriesIdentities($scan);
        $this->relocationMatcher->matchScan($scan);

        $scan->update([
            'status' => 'completed',
            'disk_statuses' => $diskStatuses,
            'discovered_count' => $discoveredCount,
            'missing_count' => $missingCount,
            'completed_at' => now(),
        ]);

        $this->queueAutomaticSeriesImports($scan);

        LibraryFinding::query()
            ->whereNotNull('resolved_at')
            ->whereIn('resolution', ['imported', 'deleted', 'relocated'])
            ->where('source_folder', '!=', '')
            ->select('id')
            ->eachById(function (LibraryFinding $finding) use ($scan): void {
                CleanupResolvedLibraryFindingFolder::dispatch($finding->id, $scan->user_id);
            });
    }

    /** @return array{int, int} */
    private function scanRoot(LibraryScan $scan, ConfiguredMediaDisk $disk): array
    {
        $files = $this->scanner->scan($disk);
        $visiblePaths = array_fill_keys(array_column($files, 'relative_path'), true);
        $trackedByPath = MediaFile::query()
            ->where('root_kind', $disk->kind)
            ->where('disk_id', $disk->id)
            ->whereNotNull('active_path_key')
            ->get()
            ->keyBy('relative_path');
        $discoveredCount = 0;
        $missingCount = 0;

        foreach ($files as $file) {
            $tracked = $trackedByPath->get($file['relative_path']);

            if ($tracked instanceof MediaFile) {
                LibraryFinding::query()
                    ->where('root_kind', $disk->kind)
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

            $attributes = $disk->kind === MediaRootKind::Movies
                ? $this->movieFindingAttributes($file)
                : $this->seriesFindingAttributes($file, $disk);

            LibraryFinding::query()->updateOrCreate(
                [
                    'library_scan_id' => $scan->id,
                    'root_kind' => $disk->kind,
                    'path_key' => LibraryFinding::pathKey($disk->id, $file['relative_path'], $disk->kind),
                    'disk_id' => $disk->id,
                    'relative_path' => $file['relative_path'],
                ],
                [
                    ...$file,
                    ...$attributes,
                    'kind' => 'discovered',
                ],
            );
            $discoveredCount++;
        }

        foreach ($trackedByPath as $relativePath => $tracked) {
            if (isset($visiblePaths[$relativePath])) {
                continue;
            }

            try {
                $trackedPath = $this->pathGuard->resolveChild($disk->root, $relativePath);

                if ($this->filesystem->pathExists($trackedPath)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            LibraryFinding::query()->updateOrCreate(
                [
                    'library_scan_id' => $scan->id,
                    'root_kind' => $disk->kind,
                    'path_key' => LibraryFinding::pathKey($disk->id, $relativePath, $disk->kind),
                    'disk_id' => $disk->id,
                    'relative_path' => $relativePath,
                ],
                [
                    'media_item_id' => $tracked->media_item_id,
                    'series_episode_id' => $tracked->series_episode_id,
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

        return [$discoveredCount, $missingCount];
    }

    /**
     * @param  array{relative_path:string,source_folder:string,source_filename:string,size_bytes:int,device_id:int,inode_id:int}  $file
     * @return array<string, mixed>
     */
    private function movieFindingAttributes(array $file): array
    {
        $identity = $this->resolveMovieIdentity($file['relative_path']);
        $existingMediaItem = $identity['tmdb_id'] === null
            ? null
            : MediaItem::query()->where('tmdb_id', $identity['tmdb_id'])->first();
        $status = $identity['status'];
        $destination = null;

        if ($identity['snapshot'] !== null) {
            $pathMovie = $existingMediaItem ?? new MediaItem($identity['snapshot']);
            $destination = $this->moviePathBuilder->build($pathMovie, $file['source_filename'])->relativePath;
        }

        if ($existingMediaItem !== null && $this->movieHasConflict($existingMediaItem)) {
            $status = 'conflict';
        }

        return [
            'media_item_id' => $existingMediaItem?->id,
            'series_episode_id' => null,
            'status' => $status,
            'identity_source' => $identity['source'],
            'identity_snapshot' => $identity['snapshot'],
            'tmdb_id' => $identity['tmdb_id'],
            'imdb_id' => $identity['imdb_id'],
            'series_category' => null,
            'season_number' => null,
            'episode_number' => null,
            'destination_relative_path' => $destination,
            'error_detail' => $identity['error'],
        ];
    }

    /**
     * @param  array{relative_path:string,source_folder:string,source_filename:string,size_bytes:int,device_id:int,inode_id:int}  $file
     * @return array<string, mixed>
     */
    private function seriesFindingAttributes(array $file, ConfiguredMediaDisk $disk): array
    {
        $parsed = $this->parseSeriesIdentity($file['relative_path']);

        if ($parsed['status'] !== 'ready'
            || $parsed['tmdb_id'] === null
            || $parsed['season_number'] === null
            || $parsed['episode_number'] === null
        ) {
            return [
                'media_item_id' => null,
                'series_episode_id' => null,
                'status' => $parsed['status'],
                'identity_source' => $parsed['tmdb_id'] === null ? null : 'tmdb_tag',
                'identity_snapshot' => null,
                'tmdb_id' => $parsed['tmdb_id'],
                'imdb_id' => null,
                'series_category' => null,
                'season_number' => $parsed['season_number'],
                'episode_number' => $parsed['episode_number'],
                'destination_relative_path' => null,
                'error_detail' => $parsed['error'],
            ];
        }

        try {
            $seriesDetails = $this->seriesTmdb->series($parsed['tmdb_id']);
            $seasonDetails = $this->seriesTmdb->season($parsed['tmdb_id'], $parsed['season_number']);
            $episodeDetails = collect($seasonDetails['episodes'])->first(
                fn (array $episode): bool => $episode['episode_number'] === $parsed['episode_number'],
            );

            if (! is_array($episodeDetails)) {
                return $this->unmappedSeriesAttributes($parsed, 'TMDB does not list the parsed episode in this season.');
            }
        } catch (MovieLookupException $exception) {
            return $this->unmappedSeriesAttributes($parsed, $exception->getMessage());
        }

        $series = Series::query()->where('tmdb_id', $parsed['tmdb_id'])->first();
        $episode = $series === null
            ? null
            : SeriesEpisode::query()
                ->whereIn('series_season_id', $series->seasons()->where('season_number', $parsed['season_number'])->select('id'))
                ->where('episode_number', $parsed['episode_number'])
                ->first();
        $category = $series === null ? $disk->seriesDefaultCategory : $series->category;
        $snapshot = [
            'series' => $seriesDetails,
            'season' => $seasonDetails,
            'episode' => $episodeDetails,
        ];
        $destination = $this->seriesDestination($seriesDetails, $seasonDetails, $episodeDetails, $file['source_filename'], $episode);
        $status = $category === null ? 'needs_identification' : 'ready';
        $error = $category === null ? 'Choose whether this new Show belongs to TV or Anime before importing.' : null;

        if ($episode !== null && $this->seriesEpisodeHasConflict($series, $episode)) {
            $status = 'conflict';
            $error = 'This Show episode already has a current file, active upload, or unresolved media operation.';
        }

        if ($status === 'ready' && $file['relative_path'] !== $destination) {
            try {
                $destinationPath = $this->pathGuard->resolveChild($disk->root, $destination);

                if ($this->filesystem->pathExists($destinationPath)) {
                    $status = 'conflict';
                    $error = 'The canonical Show episode destination is already occupied.';
                }
            } catch (Throwable) {
                $status = 'conflict';
                $error = 'The canonical Show episode destination could not be checked safely.';
            }
        }

        return [
            'media_item_id' => null,
            'series_episode_id' => $episode?->id,
            'status' => $status,
            'identity_source' => 'tmdb_tag_episode_token',
            'identity_snapshot' => $snapshot,
            'tmdb_id' => $parsed['tmdb_id'],
            'imdb_id' => null,
            'series_category' => $category,
            'season_number' => $parsed['season_number'],
            'episode_number' => $parsed['episode_number'],
            'destination_relative_path' => $destination,
            'error_detail' => $error,
        ];
    }

    /** @param array{status:string,tmdb_id:int|null,season_number:int|null,episode_number:int|null,error:string|null} $parsed
     * @return array<string, mixed>
     */
    private function unmappedSeriesAttributes(array $parsed, string $error): array
    {
        return [
            'media_item_id' => null,
            'series_episode_id' => null,
            'status' => 'needs_identification',
            'identity_source' => 'tmdb_tag_episode_token',
            'identity_snapshot' => null,
            'tmdb_id' => $parsed['tmdb_id'],
            'imdb_id' => null,
            'series_category' => null,
            'season_number' => $parsed['season_number'],
            'episode_number' => $parsed['episode_number'],
            'destination_relative_path' => null,
            'error_detail' => $error,
        ];
    }

    /** @return array{status:string,tmdb_id:int|null,season_number:int|null,episode_number:int|null,error:string|null} */
    private function parseSeriesIdentity(string $relativePath): array
    {
        preg_match_all('/\[tmdbid-(\d+)\]/i', $relativePath, $tmdbMatches);
        preg_match_all('/(?<![A-Z0-9])S(\d{1,3})E(\d{1,4})(?![A-Z0-9])/i', $relativePath, $episodeMatches, PREG_SET_ORDER);
        $tmdbIds = array_values(array_unique(array_map(fn (string $id): int => (int) $id, $tmdbMatches[1])));
        $episodeTokens = array_values(array_unique(array_map(
            fn (array $match): string => (int) $match[1].':'.(int) $match[2],
            $episodeMatches,
        )));

        if (count($tmdbIds) > 1 || count($episodeTokens) > 1) {
            return [
                'status' => 'conflict',
                'tmdb_id' => count($tmdbIds) === 1 ? $tmdbIds[0] : null,
                'season_number' => null,
                'episode_number' => null,
                'error' => 'Multiple distinct Show or episode identity tokens were found.',
            ];
        }

        $tmdbId = $tmdbIds[0] ?? null;

        if ($episodeTokens === []) {
            return [
                'status' => 'needs_identification',
                'tmdb_id' => $tmdbId,
                'season_number' => null,
                'episode_number' => null,
                'error' => 'No valid SxxExx episode token was found. Choose the season and episode manually.',
            ];
        }

        [$seasonNumber, $episodeNumber] = array_map('intval', explode(':', $episodeTokens[0]));

        if ($episodeNumber < 1) {
            return [
                'status' => 'needs_identification',
                'tmdb_id' => $tmdbId,
                'season_number' => null,
                'episode_number' => null,
                'error' => 'The Show episode token is invalid. Choose the season and episode manually.',
            ];
        }

        if (preg_match('/(?:^|[._\-\s\/])(extras?|bonus|featurettes?|sample)(?:[._\-\s\/]|$)/iu', $relativePath) === 1
            || preg_match('/(?:^|[._\-\s\/])(?:part|pt)[._\-\s]*\d+(?:[._\-\s\/]|$)/iu', $relativePath) === 1
        ) {
            return [
                'status' => 'needs_identification',
                'tmdb_id' => $tmdbId,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'error' => 'Multipart episodes and known extras require manual review.',
            ];
        }

        if ($tmdbId === null) {
            return [
                'status' => 'needs_identification',
                'tmdb_id' => null,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'error' => 'No [tmdbid-N] Show tag was found. Choose a Show manually.',
            ];
        }

        return [
            'status' => 'ready',
            'tmdb_id' => $tmdbId,
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
            'error' => null,
        ];
    }

    private function queueAutomaticSeriesImports(LibraryScan $scan): void
    {
        $automaticDiskIds = collect($this->diskRegistry->forKind(MediaRootKind::Series))
            ->filter(fn (ConfiguredMediaDisk $disk): bool => $disk->seriesDefaultCategory instanceof SeriesCategory)
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        if ($automaticDiskIds === []) {
            return;
        }

        $actor = $scan->user()->first();

        if (! $actor instanceof User || ! $actor->isAdministrator()) {
            return;
        }

        LibraryFinding::query()
            ->where('library_scan_id', $scan->id)
            ->where('root_kind', MediaRootKind::Series)
            ->where('kind', 'discovered')
            ->where('status', 'ready')
            ->where('identity_source', 'tmdb_tag_episode_token')
            ->whereIn('disk_id', $automaticDiskIds)
            ->whereNull('resolved_at')
            ->select('id')
            ->eachById(function (LibraryFinding $finding) use ($actor): void {
                $this->queueImport->execute($finding, $actor);
            });
    }

    /** @return array{status:string,source:string|null,tmdb_id:int|null,imdb_id:string|null,snapshot:array<string,mixed>|null,error:string|null} */
    private function resolveMovieIdentity(string $relativePath): array
    {
        preg_match_all('/\[tmdbid-(\d+)\]/i', $relativePath, $tmdbMatches);
        preg_match_all('/\[imdbid-(tt\d{7,12})\]/i', $relativePath, $imdbMatches);
        $tmdbIds = array_values(array_unique(array_map(fn (string $id): int => (int) $id, $tmdbMatches[1])));
        $imdbIds = array_values(array_unique(array_map(fn (string $id): string => strtolower($id), $imdbMatches[1])));

        if (count($tmdbIds) > 1 || count($imdbIds) > 1) {
            return ['status' => 'conflict', 'source' => 'tags', 'tmdb_id' => null, 'imdb_id' => null, 'snapshot' => null, 'error' => 'Multiple distinct identity tags were found.'];
        }

        if ($tmdbIds === [] && $imdbIds === []) {
            return ['status' => 'needs_identification', 'source' => null, 'tmdb_id' => null, 'imdb_id' => null, 'snapshot' => null, 'error' => null];
        }

        try {
            $fromTmdb = $tmdbIds === [] ? null : $this->tmdb->movie($tmdbIds[0]);
            $fromImdb = $imdbIds === [] ? null : $this->tmdb->findByImdb($imdbIds[0]);
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

    /** @param array<string,mixed> $seriesDetails
     * @param  array<string,mixed>  $seasonDetails
     * @param  array<string,mixed>  $episodeDetails
     */
    private function seriesDestination(
        array $seriesDetails,
        array $seasonDetails,
        array $episodeDetails,
        string $sourceFilename,
        ?SeriesEpisode $existingEpisode,
    ): string {
        if ($existingEpisode !== null) {
            return $this->seriesPathBuilder->build($existingEpisode->loadMissing('season.series'), $sourceFilename)->relativePath;
        }

        $series = new Series([
            'tmdb_id' => $seriesDetails['tmdb_id'],
            'name' => $seriesDetails['name'],
            'first_air_year' => $seriesDetails['first_air_year'],
        ]);
        $season = new SeriesSeason([
            'tmdb_id' => $seasonDetails['tmdb_id'],
            'season_number' => $seasonDetails['season_number'],
            'name' => $seasonDetails['name'],
        ]);
        $episode = new SeriesEpisode([
            'tmdb_id' => $episodeDetails['tmdb_id'],
            'episode_number' => $episodeDetails['episode_number'],
            'name' => $episodeDetails['name'],
        ]);
        $season->setRelation('series', $series);
        $episode->setRelation('season', $season);

        return $this->seriesPathBuilder->build($episode, $sourceFilename)->relativePath;
    }

    private function movieHasConflict(MediaItem $mediaItem): bool
    {
        return $mediaItem->current_media_file_id !== null
            || MediaFile::query()->where('media_item_id', $mediaItem->id)->whereNotNull('active_path_key')->exists()
            || Upload::query()->where('media_item_id', $mediaItem->id)
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists();
    }

    private function seriesEpisodeHasConflict(Series $series, SeriesEpisode $episode): bool
    {
        return $episode->current_media_file_id !== null
            || MediaFile::query()->where('series_episode_id', $episode->id)->whereNotNull('active_path_key')->exists()
            || Upload::query()->where('series_episode_id', $episode->id)
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists()
            || EpisodeRenameOperation::query()->where('series_episode_id', $episode->id)->whereNot('status', 'completed')->exists()
            || SeriesDeletionOperation::query()->where('series_id', $series->id)->whereNot('status', 'completed')->exists();
    }

    private function markDuplicateMovieIdentities(LibraryScan $scan): void
    {
        $duplicateTmdbIds = LibraryFinding::query()
            ->where('library_scan_id', $scan->id)
            ->where('root_kind', MediaRootKind::Movies)
            ->where('kind', 'discovered')
            ->whereNotNull('tmdb_id')
            ->select('tmdb_id')
            ->groupBy('tmdb_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tmdb_id');

        if ($duplicateTmdbIds->isNotEmpty()) {
            LibraryFinding::query()
                ->where('library_scan_id', $scan->id)
                ->where('root_kind', MediaRootKind::Movies)
                ->whereIn('tmdb_id', $duplicateTmdbIds)
                ->whereNull('resolved_at')
                ->update([
                    'status' => 'conflict',
                    'error_detail' => 'Multiple discovered files identify the same movie; multiple versions are not supported.',
                ]);
        }
    }

    private function markDuplicateSeriesIdentities(LibraryScan $scan): void
    {
        $duplicateIdentities = LibraryFinding::query()
            ->where('library_scan_id', $scan->id)
            ->where('root_kind', MediaRootKind::Series)
            ->where('kind', 'discovered')
            ->whereNotNull('tmdb_id')
            ->whereNotNull('season_number')
            ->whereNotNull('episode_number')
            ->select(['tmdb_id', 'season_number', 'episode_number'])
            ->groupBy(['tmdb_id', 'season_number', 'episode_number'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateIdentities as $identity) {
            LibraryFinding::query()
                ->where('library_scan_id', $scan->id)
                ->where('root_kind', MediaRootKind::Series)
                ->where('tmdb_id', $identity->tmdb_id)
                ->where('season_number', $identity->season_number)
                ->where('episode_number', $identity->episode_number)
                ->whereNull('resolved_at')
                ->update([
                    'status' => 'conflict',
                    'error_detail' => 'Multiple discovered files identify the same Show episode; multiple versions are not supported.',
                ]);
        }
    }
}
