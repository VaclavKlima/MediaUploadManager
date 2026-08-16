<?php

namespace App\Support\Series;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\EpisodeRenameOperation;
use App\Models\Series;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\MediaTechnicalTagPresenter;
use Illuminate\Database\Eloquent\Builder;

final readonly class SeriesDetailsPresenter
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaTechnicalTagPresenter $technicalTagPresenter,
    ) {}

    /** @return array<string, mixed> */
    public function present(Series $series, User $actor, ?int $requestedSeason): array
    {
        $availableSeasons = $this->availableSeasons($series);
        $selectedSeasonNumber = $this->selectedSeasonNumber($availableSeasons, $requestedSeason);
        $selectedSeason = $series->seasons()->where('season_number', $selectedSeasonNumber)->first();

        if ($selectedSeason !== null) {
            $episodes = $selectedSeason->episodes()
                ->with([
                    'currentMediaFile.sourceUpload.user',
                    'currentMediaFile.importedBy',
                ])
                ->withCount([
                    'uploads as active_uploads_count' => fn (Builder $query): Builder => $query
                        ->whereIn('status', UploadStatus::capacityReservingValues()),
                    'uploads as failed_uploads_count' => fn (Builder $query): Builder => $query
                        ->where('status', UploadStatus::Failed),
                ])
                ->get();
            $selectedSeason->setRelation('episodes', $episodes);
        }

        $availableEpisodeCount = $series->episodes()->whereNotNull('current_media_file_id')
            ->whereIn('series_season_id', $series->seasons()->select('id')->where('season_number', '>', 0))
            ->count();
        $availableSeasonCount = $series->seasons()->where('season_number', '>', 0)
            ->whereHas('episodes', fn (Builder $query): Builder => $query->whereNotNull('current_media_file_id'))
            ->count();
        $regularSeasonCount = collect($availableSeasons)->where('season_number', '>', 0)->count();
        $unresolvedDeletion = SeriesDeletionOperation::query()
            ->where('series_id', $series->id)->whereNot('status', 'completed')->first();

        return [
            'id' => $series->id,
            'tmdb_id' => $series->tmdb_id,
            'name' => $series->name,
            'original_name' => $series->original_name,
            'year' => $series->first_air_year,
            'overview' => $series->overview,
            'category' => $series->category->value,
            'poster_url' => $series->poster_path === null ? null : 'https://image.tmdb.org/t/p/w500'.$series->poster_path,
            'storage' => [
                'disk_id' => $series->home_disk_id,
                'disk_label' => $series->home_disk_id === null ? null : $this->diskRegistry
                    ->findRoot($series->home_disk_id, MediaRootKind::Series)?->label,
                'size_bytes' => (int) $series->episodes()->join('media_files', 'media_files.id', '=', 'series_episodes.current_media_file_id')->sum('media_files.size_bytes'),
            ],
            'coverage' => [
                'seasons' => ['available' => $availableSeasonCount, 'total' => $regularSeasonCount],
                'episodes' => ['available' => $availableEpisodeCount, 'total' => $series->episode_total],
            ],
            'seasons' => $availableSeasons,
            'selected_season_number' => $selectedSeasonNumber,
            'selected_season' => $selectedSeason === null ? null : $this->presentSeason($selectedSeason, $actor, $unresolvedDeletion),
            'selected_season_hydrated' => $selectedSeason !== null,
            'actions' => [
                'can_delete_show' => $actor->isAdministrator() && $unresolvedDeletion === null,
                'delete_show_blocker' => $actor->isAdministrator()
                    ? ($unresolvedDeletion === null ? null : 'A deletion operation is unresolved. Retry it before starting another change.')
                    : 'Only an administrator may permanently delete a Show.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentSeason(
        SeriesSeason $season,
        User $actor,
        ?SeriesDeletionOperation $unresolvedDeletion,
    ): array {
        $season->episodes->each->setRelation('season', $season);
        $unresolvedRenameEpisodeIds = EpisodeRenameOperation::query()
            ->whereIn('series_episode_id', $season->episodes->modelKeys())
            ->whereNot('status', 'completed')
            ->pluck('series_episode_id')
            ->all();

        return [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'name' => $season->displayName(),
            'overview' => $season->overview,
            'episodes' => $season->episodes->map(
                fn (SeriesEpisode $episode): array => $this->presentEpisode(
                    $episode,
                    $actor,
                    $unresolvedDeletion,
                    in_array($episode->id, $unresolvedRenameEpisodeIds, true),
                ),
            )->values()->all(),
            'actions' => [
                'can_delete_media' => $actor->isAdministrator() && $unresolvedDeletion === null
                    && $season->episodes->contains(fn (SeriesEpisode $episode): bool => $episode->current_media_file_id !== null),
                'delete_media_blocker' => $actor->isAdministrator()
                    ? ($unresolvedDeletion === null ? null : 'A deletion operation is unresolved.')
                    : 'Only an administrator may delete season media.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentEpisode(
        SeriesEpisode $episode,
        User $actor,
        ?SeriesDeletionOperation $unresolvedDeletion,
        bool $hasUnresolvedRename,
    ): array {
        $mediaFile = $episode->currentMediaFile;
        $owner = $mediaFile?->sourceUpload?->user;
        $owner ??= $mediaFile?->importedBy;
        $isOwner = $mediaFile?->sourceUpload?->user_id === $actor->id
            || $mediaFile?->imported_by_user_id === $actor->id;
        $activeUploads = (int) $episode->active_uploads_count;
        $failedUploads = (int) $episode->failed_uploads_count;
        $blocker = match (true) {
            $unresolvedDeletion !== null => 'A deletion operation is unresolved.',
            $hasUnresolvedRename => 'An episode rename is unresolved. Retry the same title.',
            $failedUploads > 0 => 'Discard or successfully retry the failed upload first.',
            $activeUploads > 0 => 'Finish or cancel the active upload first.',
            default => null,
        };
        $authorized = $actor->isAdministrator() || ($mediaFile !== null && $isOwner);
        $state = match (true) {
            $mediaFile !== null => 'available',
            $episode->air_date === null => 'unscheduled',
            $episode->air_date->isFuture() => 'upcoming',
            default => 'missing',
        };

        return [
            'id' => $episode->id,
            'episode_number' => $episode->episode_number,
            'identity' => sprintf('S%02dE%02d', $episode->season->season_number, $episode->episode_number),
            'name' => $episode->displayName(),
            'tmdb_name' => $episode->name,
            'custom_name' => $episode->custom_name,
            'overview' => $episode->overview,
            'air_date' => $episode->air_date?->toDateString(),
            'state' => $state,
            'current_file' => $mediaFile === null ? null : [
                'id' => $mediaFile->id,
                'relative_path' => $mediaFile->relative_path,
                'size_bytes' => $mediaFile->size_bytes,
                'technical_tags' => $this->technicalTagPresenter->present($mediaFile),
                'owner' => $owner === null ? null : ['id' => $owner->id, 'name' => $owner->name],
            ],
            'actions' => [
                'can_rename' => ($mediaFile === null ? $actor->isAdministrator() : $authorized) && $blocker === null,
                'rename_blocker' => $blocker ?? ($mediaFile === null && ! $actor->isAdministrator()
                    ? 'Only an administrator may rename a missing episode.'
                    : (! $authorized ? 'Only this episode\'s owner or an administrator may rename it.' : null)),
                'can_delete_media' => $mediaFile !== null && $authorized && $blocker === null,
                'delete_media_blocker' => $blocker ?? (! $authorized
                    ? 'Only this episode\'s owner or an administrator may delete its media.'
                    : ($mediaFile === null ? 'This episode has no media to delete.' : null)),
            ],
        ];
    }

    /** @return list<array{season_number:int,name:string,episode_count:int,hydrated:bool}> */
    private function availableSeasons(Series $series): array
    {
        $hydrated = $series->seasons()->pluck('id', 'season_number');
        $metadata = $series->metadata_snapshot['seasons'] ?? [];
        $seasons = [];

        if (is_array($metadata)) {
            foreach ($metadata as $season) {
                if (! is_array($season) || ! is_int($season['season_number'] ?? null)) {
                    continue;
                }

                $number = $season['season_number'];
                $name = is_string($season['name'] ?? null) && $season['name'] !== ''
                    ? $season['name']
                    : ($number === 0 ? 'Specials' : 'Season '.$number);
                $seasons[$number] = [
                    'season_number' => $number,
                    'name' => $number === 0 ? 'Specials' : $name,
                    'episode_count' => is_int($season['episode_count'] ?? null) ? $season['episode_count'] : 0,
                    'hydrated' => $hydrated->has($number),
                ];
            }
        }

        foreach ($series->seasons()->get(['season_number', 'name', 'episode_count']) as $season) {
            $seasons[$season->season_number] ??= [
                'season_number' => $season->season_number,
                'name' => $season->displayName(),
                'episode_count' => $season->episode_count,
                'hydrated' => true,
            ];
        }

        ksort($seasons);

        return array_values($seasons);
    }

    /** @param list<array{season_number:int,name:string,episode_count:int,hydrated:bool}> $seasons */
    private function selectedSeasonNumber(array $seasons, ?int $requestedSeason): int
    {
        if ($requestedSeason !== null && collect($seasons)->contains('season_number', $requestedSeason)) {
            return $requestedSeason;
        }

        $numbered = collect($seasons)->first(fn (array $season): bool => $season['season_number'] > 0);

        return is_array($numbered) ? $numbered['season_number'] : ($seasons[0]['season_number'] ?? 0);
    }
}
