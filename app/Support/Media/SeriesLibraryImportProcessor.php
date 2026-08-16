<?php

namespace App\Support\Media;

use App\Actions\CleanupResolvedLibraryFindingFolder;
use App\Actions\CreateOrReplayUploadReservation;
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
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\HardLinkCreationException;
use App\Support\Media\Exceptions\RelocationVerificationException;
use App\Support\SecurityAudit;
use App\Support\Series\JellyfinSeriesPathBuilder;
use App\Support\Tmdb\SeriesTmdbClient;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class SeriesLibraryImportProcessor
{
    private const LOCK_SECONDS = 240;

    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private JellyfinSeriesPathBuilder $pathBuilder,
        private FfprobeMediaValidator $validator,
        private SeriesTmdbClient $tmdb,
        private CacheManager $cacheManager,
        private CleanupResolvedLibraryFindingFolder $cleanupResolvedFolder,
        private SeriesLibraryRelocationVerifier $relocationVerifier,
    ) {}

    public function process(LibraryFinding $finding, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException('Only an administrator may import discovered Show episodes.');
        }

        $repository = $this->cacheManager->store('database');

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw new RuntimeException('Show library import locking is unavailable.');
        }

        $repository->getStore()
            ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, self::LOCK_SECONDS)
            ->block(10, function () use ($finding, $actor): void {
                $finding = $finding->refresh();

                if (in_array($finding->resolution, ['imported', 'relocated'], true)) {
                    $this->cleanupResolvedFolder->execute($finding, $actor);

                    return;
                }

                $isRestore = ($finding->operation_claim['type'] ?? null) === 'restore'
                    || $finding->paired_missing_finding_id !== null
                    || in_array($finding->status, ['restore_ready', 'restore_queued', 'restoring'], true);

                if ($isRestore) {
                    $claim = $finding->operation_claim ?? $this->validateAndClaimRestore($finding, $actor);
                    $this->moveAndCommitRestore($finding->refresh(), $actor, $claim);

                    return;
                }

                $claim = $finding->operation_claim ?? $this->validateAndClaim($finding, $actor);
                $this->moveAndCommit($finding->refresh(), $actor, $claim);
            });
    }

    /** @return array<string, mixed> */
    private function validateAndClaim(LibraryFinding $finding, User $actor): array
    {
        $category = $finding->series_category;

        if ($finding->root_kind !== MediaRootKind::Series
            || $finding->kind !== 'discovered'
            || ! in_array($finding->status, ['ready', 'failed', 'import_queued'], true)
            || ! is_int($finding->tmdb_id)
            || ! $category instanceof SeriesCategory
            || ! is_int($finding->season_number)
            || ! is_int($finding->episode_number)
        ) {
            throw new RuntimeException('This Show finding is not ready to import.');
        }

        [$seriesDetails, $seasonDetails, $episodeDetails] = $this->freshIdentity(
            $finding->tmdb_id,
            $finding->season_number,
            $finding->episode_number,
        );
        $series = Series::query()->where('tmdb_id', $finding->tmdb_id)->first();

        if ($series !== null && $series->category !== $finding->series_category) {
            throw new RuntimeException('The existing Show category changed after confirmation.');
        }

        if ($series?->home_disk_id !== null && $series->home_disk_id !== $finding->disk_id) {
            throw new RuntimeException('This Show is assigned to a different Series root.');
        }

        $episode = $this->existingEpisode($series, $finding->season_number, $finding->episode_number);
        $destination = $this->destination($seriesDetails, $seasonDetails, $episodeDetails, $finding->source_filename, $episode);

        if ($finding->destination_relative_path !== $destination) {
            throw new RuntimeException('The canonical Show episode destination changed after confirmation.');
        }

        if ($episode !== null) {
            if (! $series instanceof Series) {
                throw new RuntimeException('The catalogued Show episode is detached from its Show.');
            }

            $this->assertEpisodeAvailability($series, $episode);
        }

        $disk = $this->healthyDisk($finding->disk_id);
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);
        $this->assertSnapshot($sourcePath, $finding->size_bytes, $finding->device_id, $finding->inode_id);

        if ($this->filesystem->deviceId($disk->root) !== $finding->device_id) {
            throw new RuntimeException('The source file is no longer on its configured Series filesystem.');
        }

        $this->assertSourceUnclaimed($finding);
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $destination);

        if ($finding->relative_path !== $destination && $this->filesystem->pathExists($destinationPath)) {
            throw new RuntimeException('The canonical Show episode destination is already occupied.');
        }

        $probe = $this->validator->probe($sourcePath);
        $this->assertSnapshot($sourcePath, $finding->size_bytes, $finding->device_id, $finding->inode_id);
        $claim = [
            'version' => 1,
            'type' => 'import',
            'media_type' => 'show',
            'actor_id' => $actor->id,
            'disk_id' => $disk->id,
            'root_kind' => MediaRootKind::Series->value,
            'source_relative_path' => $finding->relative_path,
            'destination_relative_path' => $destination,
            'size_bytes' => $finding->size_bytes,
            'device_id' => $finding->device_id,
            'inode_id' => $finding->inode_id,
            'tmdb_id' => $finding->tmdb_id,
            'category' => $category->value,
            'season_number' => $finding->season_number,
            'episode_number' => $finding->episode_number,
            'series_snapshot' => $seriesDetails,
            'season_snapshot' => $seasonDetails,
            'episode_snapshot' => $episodeDetails,
            'probe' => $probe,
        ];

        DB::transaction(function () use ($finding, $claim): void {
            $locked = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($locked->operation_claim !== null) {
                return;
            }

            if ($locked->root_kind !== MediaRootKind::Series
                || ! in_array($locked->status, ['ready', 'failed', 'import_queued'], true)
                || $locked->size_bytes !== $claim['size_bytes']
                || $locked->device_id !== $claim['device_id']
                || $locked->inode_id !== $claim['inode_id']
            ) {
                throw new RuntimeException('The Show scan finding changed before import.');
            }

            $locked->update([
                'destination_relative_path' => $claim['destination_relative_path'],
                'operation_claim' => $claim,
                'status' => 'importing',
                'error_detail' => null,
            ]);
        }, attempts: 3);

        SecurityAudit::libraryImportConfirmed($finding, $actor, $destination);

        return $claim;
    }

    /** @param array<string, mixed> $claim */
    private function moveAndCommit(LibraryFinding $finding, User $actor, array $claim): void
    {
        $this->assertClaim($finding, $actor, $claim, 'import');
        $disk = $this->healthyDisk($finding->disk_id);
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $this->claimPath($claim, 'source_relative_path'));
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $this->claimPath($claim, 'destination_relative_path'));
        $this->convergePaths($sourcePath, $destinationPath, $disk->root, $claim);
        $mediaFile = DB::transaction(function () use ($finding, $actor, $claim): MediaFile {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->resolution === 'imported' && $lockedFinding->media_file_id !== null) {
                return MediaFile::query()->findOrFail($lockedFinding->media_file_id);
            }

            if (! CanonicalJson::equivalent($lockedFinding->operation_claim, $claim)) {
                throw new RuntimeException('The persisted Show import claim changed before commit.');
            }

            [$series, $episode] = $this->hydrateClaimedEpisode($claim);
            $this->assertEpisodeAvailability($series, $episode);

            if ($series->home_disk_id === null) {
                $series->update(['home_disk_id' => $claim['disk_id']]);
            } elseif ($series->home_disk_id !== $claim['disk_id']) {
                throw new RuntimeException('The Show home disk changed before import commit.');
            }

            $probe = $this->stringKeyedArray($claim['probe'] ?? null);
            $mediaFile = MediaFile::query()->create([
                'media_item_id' => null,
                'series_episode_id' => $episode->id,
                'source_upload_id' => null,
                'imported_by_user_id' => $actor->id,
                'import_provenance' => [
                    'type' => 'recursive_series_library_import',
                    'library_scan_id' => $lockedFinding->library_scan_id,
                    'library_finding_id' => $lockedFinding->id,
                    'source_relative_path' => $claim['source_relative_path'],
                    'relocation_proof' => [
                        'type' => 'inode',
                        'size_bytes' => $claim['size_bytes'],
                        'device_id' => $claim['device_id'],
                        'inode_id' => $claim['inode_id'],
                    ],
                ],
                'disk_id' => $claim['disk_id'],
                'root_kind' => MediaRootKind::Series,
                'relative_path' => $claim['destination_relative_path'],
                'size_bytes' => $claim['size_bytes'],
                'container' => $probe['container'],
                'duration_milliseconds' => $probe['duration_milliseconds'],
                'video_metadata' => $probe['video'],
                'audio_metadata' => $probe['audio'],
                'probe_snapshot' => $probe['snapshot'],
                'finalized_at' => now(),
            ]);
            $episode->update(['current_media_file_id' => $mediaFile->id]);
            $series->update(['last_episode_finalized_at' => $mediaFile->finalized_at]);
            $lockedFinding->update([
                'series_episode_id' => $episode->id,
                'media_file_id' => $mediaFile->id,
                'status' => 'resolved',
                'resolution' => 'imported',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);

            return $mediaFile;
        }, attempts: 3);

        SecurityAudit::libraryImportCompleted($finding, $mediaFile, $actor);
        $this->cleanupResolvedFolder->execute($finding->refresh(), $actor);
    }

    /** @return array<string, mixed> */
    private function validateAndClaimRestore(LibraryFinding $finding, User $actor): array
    {
        if ($finding->root_kind !== MediaRootKind::Series
            || $finding->kind !== 'discovered'
            || $finding->paired_missing_finding_id === null
            || ! in_array($finding->status, ['restore_ready', 'restore_queued', 'failed'], true)
            || $finding->series_episode_id === null
            || $finding->destination_relative_path === null
        ) {
            throw new RuntimeException('This Show finding is not ready to restore.');
        }

        $missing = LibraryFinding::query()->findOrFail($finding->paired_missing_finding_id);

        try {
            $proof = $this->relocationVerifier->prove(
                $finding,
                $missing,
                $finding->series_episode_id,
                $finding->destination_relative_path,
            );
        } catch (RelocationVerificationException $exception) {
            if ($exception->reason === 'old_path_returned') {
                $this->resolveReturnedOldPath($finding, $missing);
            }

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $episode = SeriesEpisode::query()->with('season.series')->findOrFail($finding->series_episode_id);
        $destination = $this->pathBuilder->build($episode, $finding->source_filename)->relativePath;

        if ($destination !== $finding->destination_relative_path) {
            throw new RuntimeException('The canonical Show destination changed after relocation verification.');
        }

        $claim = [
            'version' => 1,
            'type' => 'restore',
            'media_type' => 'show',
            'actor_id' => $actor->id,
            'series_episode_id' => $episode->id,
            'old_media_file_id' => $missing->media_file_id,
            'missing_finding_id' => $missing->id,
            'disk_id' => $finding->disk_id,
            'root_kind' => MediaRootKind::Series->value,
            'source_relative_path' => $finding->relative_path,
            'destination_relative_path' => $destination,
            'tracked_disk_id' => $missing->disk_id,
            'tracked_relative_path' => $missing->relative_path,
            'size_bytes' => $finding->size_bytes,
            'device_id' => $finding->device_id,
            'inode_id' => $finding->inode_id,
            'proof' => $proof,
        ];

        DB::transaction(function () use ($finding, $missing, $claim): void {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();
            $lockedMissing = LibraryFinding::query()->whereKey($missing)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->operation_claim !== null) {
                return;
            }

            if ($lockedFinding->paired_missing_finding_id !== $lockedMissing->id
                || $lockedMissing->resolved_at !== null
                || $lockedMissing->status !== 'missing'
                || $lockedFinding->series_episode_id !== $lockedMissing->series_episode_id
                || ! in_array($lockedFinding->status, ['restore_ready', 'restore_queued', 'failed'], true)
            ) {
                throw new RuntimeException('The Show relocation pair changed before it could be claimed.');
            }

            $lockedFinding->update(['operation_claim' => $claim, 'status' => 'restoring', 'error_detail' => null]);
        }, attempts: 3);

        SecurityAudit::libraryRelocationConfirmed($finding, $missing, $actor, $destination);

        return $claim;
    }

    /** @param array<string, mixed> $claim */
    private function moveAndCommitRestore(LibraryFinding $finding, User $actor, array $claim): void
    {
        $this->assertClaim($finding, $actor, $claim, 'restore');
        $disk = $this->healthyDisk($finding->disk_id);
        $trackedDisk = $this->healthyDisk($this->claimPath($claim, 'tracked_disk_id'));
        $sourcePath = $this->pathGuard->resolveChild($disk->root, $this->claimPath($claim, 'source_relative_path'));
        $destinationPath = $this->pathGuard->resolveChild($disk->root, $this->claimPath($claim, 'destination_relative_path'));
        $trackedPath = $this->pathGuard->resolveChild($trackedDisk->root, $this->claimPath($claim, 'tracked_relative_path'));

        if ($this->filesystem->pathExists($trackedPath)) {
            throw new RuntimeException('The tracked Show path returned after relocation was claimed.');
        }

        $this->convergePaths($sourcePath, $destinationPath, $disk->root, $claim);
        $proof = $this->stringKeyedArray($claim['proof'] ?? null);
        $this->relocationVerifier->assertProof($destinationPath, $proof);
        $mediaFile = DB::transaction(function () use ($finding, $actor, $claim, $proof): MediaFile {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->resolution === 'relocated'
                && $lockedFinding->media_file_id !== null
                && $lockedFinding->media_file_id !== $claim['old_media_file_id']
            ) {
                return MediaFile::query()->findOrFail($lockedFinding->media_file_id);
            }

            $lockedMissing = LibraryFinding::query()->whereKey($claim['missing_finding_id'])->lockForUpdate()->firstOrFail();
            $episode = SeriesEpisode::query()->whereKey($claim['series_episode_id'])->lockForUpdate()->firstOrFail();
            $oldMediaFile = MediaFile::query()->whereKey($claim['old_media_file_id'])->lockForUpdate()->firstOrFail();

            if (! CanonicalJson::equivalent($lockedFinding->operation_claim, $claim)
                || $lockedFinding->paired_missing_finding_id !== $lockedMissing->id
                || $lockedMissing->resolved_at !== null
                || $lockedMissing->media_file_id !== $oldMediaFile->id
                || $episode->current_media_file_id !== $oldMediaFile->id
                || $oldMediaFile->series_episode_id !== $episode->id
                || $oldMediaFile->root_kind !== MediaRootKind::Series
                || $oldMediaFile->active_path_key !== MediaFile::activePathKey($oldMediaFile->disk_id, $oldMediaFile->relative_path, MediaRootKind::Series)
                || $oldMediaFile->removed_at !== null
            ) {
                throw new RuntimeException('The Show relocation database state changed before commit.');
            }

            $series = $episode->season()->firstOrFail()->series()->lockForUpdate()->firstOrFail();

            if ($series->home_disk_id === null) {
                $series->update(['home_disk_id' => $claim['disk_id']]);
            } elseif ($series->home_disk_id !== $claim['disk_id']) {
                throw new RuntimeException('The Show home disk changed before relocation commit.');
            }

            $this->assertNoUnresolvedOperations($series, $episode);
            $sourceUploadId = is_int($proof['source_upload_id'] ?? null) ? $proof['source_upload_id'] : null;

            if (Upload::query()->where('series_episode_id', $episode->id)
                ->when($sourceUploadId !== null, fn ($query) => $query->whereKeyNot($sourceUploadId))
                ->whereIn('status', [
                    UploadStatus::Pending->value,
                    UploadStatus::Uploading->value,
                    UploadStatus::Paused->value,
                    UploadStatus::Processing->value,
                    UploadStatus::Failed->value,
                ])->exists()
            ) {
                throw new RuntimeException('Another Show upload became active before relocation commit.');
            }

            $oldMediaFile->update(['removed_at' => now(), 'removal_reason' => 'relocated']);
            $mediaFile = MediaFile::query()->create([
                'media_item_id' => null,
                'series_episode_id' => $episode->id,
                'source_upload_id' => null,
                'imported_by_user_id' => $actor->id,
                'import_provenance' => [
                    'type' => 'series_library_relocation_restore',
                    'previous_media_file_id' => $oldMediaFile->id,
                    'library_scan_id' => $lockedFinding->library_scan_id,
                    'library_finding_id' => $lockedFinding->id,
                    'missing_finding_id' => $lockedMissing->id,
                    'source_relative_path' => $claim['source_relative_path'],
                    'relocation_proof' => $proof,
                ],
                'disk_id' => $claim['disk_id'],
                'root_kind' => MediaRootKind::Series,
                'relative_path' => $claim['destination_relative_path'],
                'size_bytes' => $oldMediaFile->size_bytes,
                'container' => $oldMediaFile->container,
                'duration_milliseconds' => $oldMediaFile->duration_milliseconds,
                'video_metadata' => $oldMediaFile->video_metadata,
                'audio_metadata' => $oldMediaFile->audio_metadata,
                'probe_snapshot' => $oldMediaFile->probe_snapshot,
                'finalized_at' => now(),
            ]);
            $episode->update(['current_media_file_id' => $mediaFile->id]);
            $series->update(['last_episode_finalized_at' => $mediaFile->finalized_at]);
            $lockedFinding->update([
                'media_file_id' => $mediaFile->id,
                'status' => 'resolved',
                'resolution' => 'relocated',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);
            $lockedMissing->update([
                'status' => 'resolved',
                'resolution' => 'relocated',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);

            return $mediaFile;
        }, attempts: 3);

        SecurityAudit::libraryRelocationCompleted($finding, $mediaFile, $actor);
        $this->cleanupResolvedFolder->execute($finding->refresh(), $actor);
    }

    /** @param array<string,mixed> $claim
     * @return array{Series,SeriesEpisode}
     */
    private function hydrateClaimedEpisode(array $claim): array
    {
        $seriesSnapshot = $this->stringKeyedArray($claim['series_snapshot'] ?? null);
        $seasonSnapshot = $this->stringKeyedArray($claim['season_snapshot'] ?? null);
        $episodeSnapshot = $this->stringKeyedArray($claim['episode_snapshot'] ?? null);
        $category = SeriesCategory::from($this->claimPath($claim, 'category'));
        $episodes = $seasonSnapshot['episodes'] ?? null;

        if (! is_array($episodes) || ! array_is_list($episodes)) {
            throw new RuntimeException('The persisted Show season episode list is invalid.');
        }

        $series = Series::query()->firstOrCreate(
            ['tmdb_id' => $claim['tmdb_id']],
            [
                'category' => $category,
                'name' => $seriesSnapshot['name'],
                'original_name' => $seriesSnapshot['original_name'],
                'first_air_date' => $seriesSnapshot['first_air_date'],
                'first_air_year' => $seriesSnapshot['first_air_year'],
                'overview' => $seriesSnapshot['overview'],
                'poster_path' => $seriesSnapshot['poster_path'],
                'original_language' => $seriesSnapshot['original_language'],
                'external_ids' => $seriesSnapshot['external_ids'],
                'episode_total' => $seriesSnapshot['number_of_episodes'],
                'metadata_version' => 1,
                'metadata_snapshot' => $seriesSnapshot,
            ],
        );

        if ($series->category !== $category) {
            throw new RuntimeException('The existing Show category does not match the import claim.');
        }

        $season = SeriesSeason::query()->firstOrCreate(
            ['series_id' => $series->id, 'season_number' => $claim['season_number']],
            [
                'tmdb_id' => $seasonSnapshot['tmdb_id'],
                'name' => $claim['season_number'] === 0 ? 'Specials' : $seasonSnapshot['name'],
                'overview' => $seasonSnapshot['overview'],
                'poster_path' => $seasonSnapshot['poster_path'],
                'air_date' => $seasonSnapshot['air_date'],
                'episode_count' => count($episodes),
                'metadata_version' => 1,
                'metadata_snapshot' => $seasonSnapshot,
            ],
        );

        if ($season->tmdb_id !== $seasonSnapshot['tmdb_id']) {
            throw new RuntimeException('The catalogued Show season does not match the import claim.');
        }

        $episode = SeriesEpisode::query()->firstOrCreate(
            ['series_season_id' => $season->id, 'episode_number' => $claim['episode_number']],
            [
                'tmdb_id' => $episodeSnapshot['tmdb_id'],
                'name' => $episodeSnapshot['name'],
                'overview' => $episodeSnapshot['overview'],
                'air_date' => $episodeSnapshot['air_date'],
                'runtime_minutes' => $episodeSnapshot['runtime_minutes'],
                'metadata_version' => 1,
                'metadata_snapshot' => $episodeSnapshot,
            ],
        );

        if ($episode->tmdb_id !== $episodeSnapshot['tmdb_id']) {
            throw new RuntimeException('The catalogued Show episode does not match the import claim.');
        }

        return [$series, $episode];
    }

    private function assertEpisodeAvailability(Series $series, SeriesEpisode $episode): void
    {
        if ($episode->current_media_file_id !== null
            || MediaFile::query()->where('series_episode_id', $episode->id)->whereNotNull('active_path_key')->exists()
            || Upload::query()->where('series_episode_id', $episode->id)
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists()
        ) {
            throw new RuntimeException('This Show episode already has a current file or active upload.');
        }

        $this->assertNoUnresolvedOperations($series, $episode);
    }

    private function assertNoUnresolvedOperations(Series $series, SeriesEpisode $episode): void
    {
        if (EpisodeRenameOperation::query()->where('series_episode_id', $episode->id)->whereNot('status', 'completed')->exists()
            || SeriesDeletionOperation::query()->where('series_id', $series->id)->whereNot('status', 'completed')->exists()
        ) {
            throw new RuntimeException('Resolve the Show rename or deletion operation before importing.');
        }
    }

    private function assertSourceUnclaimed(LibraryFinding $finding): void
    {
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
            throw new RuntimeException('The source Show file is claimed by an upload.');
        }
    }

    /** @return array{array<string,mixed>,array<string,mixed>,array<string,mixed>} */
    private function freshIdentity(int $tmdbId, int $seasonNumber, int $episodeNumber): array
    {
        $series = $this->tmdb->series($tmdbId);
        $season = $this->tmdb->season($tmdbId, $seasonNumber);
        $episode = collect($season['episodes'])->first(fn (array $item): bool => $item['episode_number'] === $episodeNumber);

        if (! is_array($episode)) {
            throw new RuntimeException('TMDB no longer lists the selected Show episode.');
        }

        return [$series, $season, $episode];
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

    /** @param array<string, mixed> $claim */
    private function convergePaths(string $sourcePath, string $destinationPath, string $root, array $claim): void
    {
        $sourceExists = $this->filesystem->pathExists($sourcePath);
        $destinationExists = $this->filesystem->pathExists($destinationPath);

        if ($sourceExists) {
            $this->assertSnapshot($sourcePath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        }

        if ($destinationExists) {
            $this->assertSnapshot($destinationPath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
        }

        if (! $sourceExists && ! $destinationExists) {
            throw new RuntimeException('The claimed Show episode bytes are missing.');
        }

        if ($sourcePath !== $destinationPath) {
            if ($sourceExists && ! $destinationExists) {
                $this->ensureDestinationDirectory(
                    $root,
                    dirname($this->claimPath($claim, 'destination_relative_path')),
                );

                $this->createHardLinkExclusively($sourcePath, $destinationPath);
                $destinationExists = true;
            }

            if ($sourceExists && ! $this->filesystem->sameInode($sourcePath, $destinationPath)) {
                throw new RuntimeException('The Show source and destination do not reference the same file.');
            }

            if ($sourceExists && ! $this->filesystem->deleteFile($sourcePath)) {
                throw new RuntimeException('The Show source path could not be released after linking.');
            }
        }

        $this->assertSnapshot($destinationPath, $claim['size_bytes'], $claim['device_id'], $claim['inode_id']);
    }

    private function ensureDestinationDirectory(string $root, string $relativeDirectory): void
    {
        if ($relativeDirectory === '.') {
            return;
        }

        $currentDirectory = rtrim($root, DIRECTORY_SEPARATOR);

        foreach (explode('/', str_replace('\\', '/', $relativeDirectory)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The canonical Show destination directory is unsafe.');
            }

            $currentDirectory .= DIRECTORY_SEPARATOR.$segment;

            if ($this->filesystem->pathExists($currentDirectory)) {
                if (! $this->filesystem->isDirectory($currentDirectory)
                    || $this->filesystem->isSymbolicLink($currentDirectory)
                ) {
                    throw new RuntimeException('The canonical Show destination directory is unsafe.');
                }

                continue;
            }

            if (! $this->filesystem->createDirectory($currentDirectory)) {
                throw new RuntimeException('The canonical Show destination directory could not be created.');
            }
        }
    }

    private function createHardLinkExclusively(string $sourcePath, string $destinationPath): void
    {
        try {
            $created = $this->filesystem->createHardLinkExclusively($sourcePath, $destinationPath);
        } catch (HardLinkCreationException $exception) {
            throw new RuntimeException(
                "Hard-link creation was denied by the media filesystem. Set MEDIA_GID to the source file's numeric group ID, recreate the media services, and retry the import.",
                previous: $exception,
            );
        }

        if (! $created) {
            throw new RuntimeException('The canonical Show destination could not be reserved exclusively.');
        }
    }

    private function resolveReturnedOldPath(LibraryFinding $finding, LibraryFinding $missing): void
    {
        DB::transaction(function () use ($finding, $missing): void {
            $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();
            $lockedMissing = LibraryFinding::query()->whereKey($missing)->lockForUpdate()->firstOrFail();

            if ($lockedFinding->operation_claim !== null || $lockedMissing->resolved_at !== null) {
                return;
            }

            $lockedMissing->update([
                'status' => 'resolved',
                'resolution' => 'restored',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);
            $lockedFinding->update([
                'paired_missing_finding_id' => null,
                'status' => 'conflict',
                'error_detail' => 'The tracked path returned; this discovered Show file is now a normal conflict.',
            ]);
        }, attempts: 3);
    }

    private function healthyDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->findRoot($diskId, MediaRootKind::Series);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw new RuntimeException('The Series root is unavailable or its marker identity changed.');
        }

        return $disk;
    }

    private function assertSnapshot(string $path, mixed $size, mixed $device, mixed $inode): void
    {
        if (! is_int($size)
            || ! is_int($device)
            || ! is_int($inode)
            || ! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $size
            || $this->filesystem->deviceId($path) !== $device
            || $this->filesystem->inodeId($path) !== $inode
        ) {
            throw new RuntimeException('The Show file no longer matches its verified scan snapshot.');
        }
    }

    /** @param array<string, mixed> $claim */
    private function assertClaim(LibraryFinding $finding, User $actor, array $claim, string $type): void
    {
        if (($claim['version'] ?? null) !== 1
            || ($claim['type'] ?? null) !== $type
            || ($claim['media_type'] ?? null) !== 'show'
            || ($claim['actor_id'] ?? null) !== $actor->id
            || ($claim['disk_id'] ?? null) !== $finding->disk_id
            || ($claim['root_kind'] ?? null) !== MediaRootKind::Series->value
            || ($claim['source_relative_path'] ?? null) !== $finding->relative_path
            || ($claim['size_bytes'] ?? null) !== $finding->size_bytes
            || ($claim['device_id'] ?? null) !== $finding->device_id
            || ($claim['inode_id'] ?? null) !== $finding->inode_id
        ) {
            throw new RuntimeException('The persisted Show file-operation claim is invalid.');
        }
    }

    /** @param array<string, mixed> $claim */
    private function claimPath(array $claim, string $key): string
    {
        $value = $claim[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('The persisted Show import claim is invalid.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('The persisted Show import snapshot is invalid.');
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('The persisted Show import snapshot has an invalid key.');
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
