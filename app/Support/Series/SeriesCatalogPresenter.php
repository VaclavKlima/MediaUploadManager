<?php

namespace App\Support\Series;

use App\Enums\MediaRootKind;
use App\Models\Series;
use App\Models\SeriesSeason;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class SeriesCatalogPresenter
{
    public function __construct(private ConfiguredDiskRegistry $diskRegistry) {}

    /**
     * @param  array{search:string|null,status:string|null,sort:string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $query = Series::query()
            ->select([
                'id', 'tmdb_id', 'category', 'name', 'original_name', 'first_air_year',
                'poster_path', 'episode_total', 'metadata_snapshot', 'home_disk_id',
                'last_episode_finalized_at', 'created_at',
            ])
            ->withCount([
                'episodes as available_episode_count' => fn (Builder $query): Builder => $query
                    ->whereNotNull('series_episodes.current_media_file_id')
                    ->whereIn('series_season_id', $this->numberedSeasonIds()),
                'seasons as hydrated_numbered_season_count' => fn (Builder $query): Builder => $query
                    ->where('season_number', '>', 0),
                'seasons as available_season_count' => fn (Builder $query): Builder => $query
                    ->where('season_number', '>', 0)
                    ->whereHas('episodes', fn (Builder $query): Builder => $query->whereNotNull('current_media_file_id')),
                'episodes as missing_aired_episode_count' => fn (Builder $query): Builder => $query
                    ->whereIn('series_season_id', $this->numberedSeasonIds())
                    ->whereDate('series_episodes.air_date', '<=', today())
                    ->whereNull('series_episodes.current_media_file_id'),
            ]);

        $this->applySearch($query, $filters['search']);
        $this->applyStatus($query, $filters['status']);

        match ($filters['sort']) {
            'title' => $query->orderBy('name')->orderByDesc('first_air_year')->orderBy('id'),
            'coverage' => $query
                ->orderByRaw('(available_episode_count * 1.0) / CASE WHEN episode_total = 0 THEN 1 ELSE episode_total END DESC')
                ->orderBy('name'),
            default => $query->orderByDesc('last_episode_finalized_at')->latest('created_at')->latest('id'),
        };

        return $query->paginate(48)->withQueryString()->through(
            fn (Series $series): array => $this->present($series, $actor),
        );
    }

    /** @param Builder<Series> $query */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search): void {
            $query->whereLike('name', "%{$search}%")
                ->orWhereLike('original_name', "%{$search}%");

            if (preg_match('/\A[0-9]+\z/', $search) === 1) {
                $query->orWhere('tmdb_id', (int) $search);
            }
        });
    }

    /** @param Builder<Series> $query */
    private function applyStatus(Builder $query, ?string $status): void
    {
        match ($status) {
            'complete' => $query
                ->where('episode_total', '>', 0)
                ->whereRaw('(select count(*) from series_episodes inner join series_seasons on series_seasons.id = series_episodes.series_season_id where series_seasons.series_id = series.id and series_seasons.season_number > 0 and series_episodes.current_media_file_id is not null) >= series.episode_total'),
            'missing' => $query->whereHas('seasons', fn (Builder $query): Builder => $query
                ->where('season_number', '>', 0)
                ->whereHas('episodes', fn (Builder $query): Builder => $query
                    ->whereDate('air_date', '<=', today())
                    ->whereNull('current_media_file_id'))),
            'empty' => $query->whereDoesntHave('episodes', fn (Builder $query): Builder => $query
                ->whereNotNull('current_media_file_id')),
            default => null,
        };
    }

    /** @return Builder<SeriesSeason> */
    private function numberedSeasonIds(): Builder
    {
        return SeriesSeason::query()->select('id')->where('season_number', '>', 0);
    }

    /** @return array<string, mixed> */
    private function present(Series $series, User $actor): array
    {
        $availableEpisodes = (int) $series->available_episode_count;
        $episodeTotal = max(0, $series->episode_total);
        $seasonTotal = $this->numberedSeasonTotal($series);
        $availableSeasons = (int) $series->available_season_count;
        $hasMissingAired = (int) $series->missing_aired_episode_count > 0;
        $state = match (true) {
            $availableEpisodes === 0 => 'empty',
            $episodeTotal > 0 && $availableEpisodes >= $episodeTotal => 'complete',
            $hasMissingAired => 'missing',
            default => 'in_progress',
        };

        return [
            'id' => $series->id,
            'name' => $series->name,
            'original_name' => $series->original_name,
            'year' => $series->first_air_year,
            'tmdb_id' => $series->tmdb_id,
            'category' => $series->category->value,
            'poster_url' => $series->poster_path === null ? null : 'https://image.tmdb.org/t/p/w342'.$series->poster_path,
            'state' => $state,
            'coverage' => [
                'seasons' => ['available' => $availableSeasons, 'total' => $seasonTotal],
                'episodes' => ['available' => $availableEpisodes, 'total' => $episodeTotal],
            ],
            'home_disk' => [
                'id' => $series->home_disk_id,
                'label' => $series->home_disk_id === null ? null : $this->diskRegistry
                    ->findRoot($series->home_disk_id, MediaRootKind::Series)?->label,
            ],
            'latest_finalization' => $series->last_episode_finalized_at?->toISOString(),
            'can_delete_show' => $actor->isAdministrator(),
        ];
    }

    private function numberedSeasonTotal(Series $series): int
    {
        $metadata = $series->metadata_snapshot['seasons'] ?? null;

        if (! is_array($metadata)) {
            return (int) $series->hydrated_numbered_season_count;
        }

        return collect($metadata)->filter(
            fn (mixed $season): bool => is_array($season) && is_int($season['season_number'] ?? null) && $season['season_number'] > 0,
        )->count();
    }
}
