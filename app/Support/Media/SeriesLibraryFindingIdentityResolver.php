<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Enums\UploadStatus;
use App\Models\EpisodeRenameOperation;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\RelocationVerificationException;
use App\Support\Series\JellyfinSeriesPathBuilder;
use App\Support\Tmdb\SeriesTmdbClient;
use RuntimeException;
use Throwable;

final readonly class SeriesLibraryFindingIdentityResolver
{
    public function __construct(
        private SeriesTmdbClient $tmdb,
        private JellyfinSeriesPathBuilder $pathBuilder,
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private SeriesLibraryRelocationVerifier $relocationVerifier,
    ) {}

    public function resolve(
        LibraryFinding $finding,
        int $tmdbId,
        ?SeriesCategory $requestedCategory,
        int $seasonNumber,
        int $episodeNumber,
    ): SeriesLibraryFindingIdentityDecision {
        $this->assertIdentifiable($finding);
        $seriesDetails = $this->tmdb->series($tmdbId);
        $seasonDetails = $this->tmdb->season($tmdbId, $seasonNumber);
        $episodeDetails = collect($seasonDetails['episodes'])->first(
            fn (array $episode): bool => $episode['episode_number'] === $episodeNumber,
        );

        if (! is_array($episodeDetails)) {
            throw new RuntimeException('TMDB does not list that episode in the selected season.');
        }

        $existingSeries = Series::query()->where('tmdb_id', $tmdbId)->first();

        if ($existingSeries !== null && $requestedCategory !== null && $requestedCategory !== $existingSeries->category) {
            throw new RuntimeException('An existing Show category cannot be changed through a library scan.');
        }

        $category = $existingSeries->category ?? $requestedCategory;

        if ($category === null) {
            throw new RuntimeException('Choose TV or Anime for this new Show.');
        }

        $existingEpisode = $this->existingEpisode($existingSeries, $seasonNumber, $episodeNumber);
        $destination = $this->destination(
            $seriesDetails,
            $seasonDetails,
            $episodeDetails,
            $finding->source_filename,
            $existingEpisode,
        );
        $duplicateFindingIds = [];
        $duplicateFindings = LibraryFinding::query()
            ->where('library_scan_id', $finding->library_scan_id)
            ->whereKeyNot($finding->id)
            ->where('root_kind', MediaRootKind::Series)
            ->where('kind', 'discovered')
            ->where('tmdb_id', $tmdbId)
            ->where('season_number', $seasonNumber)
            ->where('episode_number', $episodeNumber)
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->get(['id']);

        foreach ($duplicateFindings as $duplicateFinding) {
            $duplicateFindingIds[] = $duplicateFinding->id;
        }
        $operation = 'import';
        $relocation = null;

        if ($existingEpisode !== null && $duplicateFindingIds === []) {
            $missingCandidates = LibraryFinding::query()
                ->where('library_scan_id', $finding->library_scan_id)
                ->where('root_kind', MediaRootKind::Series)
                ->where('kind', 'missing')
                ->where('status', 'missing')
                ->whereNull('resolved_at')
                ->where('series_episode_id', $existingEpisode->id)
                ->get();

            if ($missingCandidates->count() === 1) {
                $missing = $missingCandidates->first();

                if ($missing instanceof LibraryFinding && is_int($missing->media_file_id)) {
                    try {
                        $this->relocationVerifier->prove($finding, $missing, $existingEpisode->id, $destination);
                        $operation = 'restore';
                        $relocation = [
                            'finding_id' => $missing->id,
                            'media_file_id' => $missing->media_file_id,
                            'disk_id' => $missing->disk_id,
                            'relative_path' => $missing->relative_path,
                            'size_bytes' => $missing->size_bytes,
                        ];
                    } catch (RelocationVerificationException) {
                        $relocation = null;
                    }
                }
            }
        }

        [$blockerCode, $blockerMessage] = $this->blocker(
            $finding,
            $existingSeries,
            $existingEpisode,
            $duplicateFindingIds,
            $destination,
            $operation,
        );

        return new SeriesLibraryFindingIdentityDecision(
            tmdbId: $tmdbId,
            category: $category,
            seasonNumber: $seasonNumber,
            episodeNumber: $episodeNumber,
            snapshot: [
                'series' => $seriesDetails,
                'season' => $seasonDetails,
                'episode' => $episodeDetails,
            ],
            destinationRelativePath: $destination,
            existingSeriesId: $existingSeries?->id,
            existingEpisodeId: $existingEpisode?->id,
            duplicateFindingIds: $duplicateFindingIds,
            blockerCode: $blockerCode,
            blockerMessage: $blockerMessage,
            operation: $operation,
            relocation: $relocation,
        );
    }

    private function assertIdentifiable(LibraryFinding $finding): void
    {
        if ($finding->root_kind !== MediaRootKind::Series
            || $finding->kind !== 'discovered'
            || $finding->resolved_at !== null
            || $finding->operation_claim !== null
            || ! in_array($finding->status, ['needs_identification', 'conflict', 'ready', 'failed'], true)
        ) {
            throw new RuntimeException('This Show finding can no longer be identified.');
        }
    }

    private function existingEpisode(?Series $series, int $seasonNumber, int $episodeNumber): ?SeriesEpisode
    {
        if ($series === null) {
            return null;
        }

        return SeriesEpisode::query()
            ->whereIn('series_season_id', $series->seasons()->where('season_number', $seasonNumber)->select('id'))
            ->where('episode_number', $episodeNumber)
            ->first();
    }

    /**
     * @param  list<int>  $duplicateFindingIds
     * @return array{string|null,string|null}
     */
    private function blocker(
        LibraryFinding $finding,
        ?Series $series,
        ?SeriesEpisode $episode,
        array $duplicateFindingIds,
        string $destination,
        string $operation,
    ): array {
        if ($duplicateFindingIds !== []) {
            return ['duplicate_finding', 'Another unresolved finding in this scan identifies the same Show episode.'];
        }

        if ($operation !== 'restore' && $episode !== null && $this->episodeHasConflict($series, $episode)) {
            return ['database_conflict', 'This Show episode already has a current file, active upload, or unresolved media operation.'];
        }

        if ($series?->home_disk_id !== null && $series->home_disk_id !== $finding->disk_id) {
            return ['home_disk_mismatch', 'This Show is assigned to a different Series root.'];
        }

        try {
            $disk = $this->diskRegistry->findRoot($finding->disk_id, MediaRootKind::Series);

            if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
                return ['disk_unavailable', 'The source Series root is unavailable or its marker identity changed.'];
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
                return ['source_filesystem_changed', 'The source file is no longer on its configured Series filesystem.'];
            }

            if (Upload::query()
                ->where('root_kind', MediaRootKind::Series)
                ->where('disk_id', $finding->disk_id)
                ->where(function ($query) use ($finding): void {
                    $query->where('target_relative_path', $finding->relative_path)
                        ->orWhere('staging_relative_path', $finding->relative_path);
                })
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists()
            ) {
                return ['source_claimed', 'The source file is claimed by an upload.'];
            }

            $destinationPath = $this->pathGuard->resolveChild($disk->root, $destination);

            if ($finding->relative_path !== $destination && $this->filesystem->pathExists($destinationPath)) {
                return ['destination_occupied', 'The canonical Show episode destination is already occupied.'];
            }
        } catch (Throwable) {
            return ['filesystem_unavailable', 'The source and destination could not be checked safely.'];
        }

        return [null, null];
    }

    private function episodeHasConflict(?Series $series, SeriesEpisode $episode): bool
    {
        return $episode->current_media_file_id !== null
            || MediaFile::query()->where('series_episode_id', $episode->id)->whereNotNull('active_path_key')->exists()
            || Upload::query()->where('series_episode_id', $episode->id)
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists()
            || EpisodeRenameOperation::query()->where('series_episode_id', $episode->id)->whereNot('status', 'completed')->exists()
            || ($series !== null
                && SeriesDeletionOperation::query()->where('series_id', $series->id)->whereNot('status', 'completed')->exists());
    }

    /** @param array<string,mixed> $seriesDetails
     * @param  array<string,mixed>  $seasonDetails
     * @param  array<string,mixed>  $episodeDetails
     */
    private function destination(
        array $seriesDetails,
        array $seasonDetails,
        array $episodeDetails,
        string $sourceFilename,
        ?SeriesEpisode $existingEpisode,
    ): string {
        if ($existingEpisode !== null) {
            return $this->pathBuilder->build($existingEpisode->loadMissing('season.series'), $sourceFilename)->relativePath;
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

        return $this->pathBuilder->build($episode, $sourceFilename)->relativePath;
    }
}
