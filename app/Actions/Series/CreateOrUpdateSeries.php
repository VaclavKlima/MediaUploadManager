<?php

namespace App\Actions\Series;

use App\Enums\SeriesCategory;
use App\Models\Series;
use App\Models\SeriesSeason;
use App\Support\Tmdb\SeriesTmdbClient;
use Illuminate\Support\Facades\DB;

final readonly class CreateOrUpdateSeries
{
    public function __construct(private SeriesTmdbClient $tmdb) {}

    /** @param list<int>|null $seasonNumbers */
    public function execute(int $tmdbId, SeriesCategory $category, ?array $seasonNumbers = null): Series
    {
        $details = $this->tmdb->series($tmdbId);
        $availableSeasons = collect($details['seasons'])->keyBy('season_number');
        $requestedSeasons = $seasonNumbers === null
            ? $availableSeasons->keys()->all()
            : array_values(array_unique($seasonNumbers));

        return DB::transaction(function () use ($details, $category, $availableSeasons, $requestedSeasons): Series {
            $series = Series::query()->firstOrCreate(
                ['tmdb_id' => $details['tmdb_id']],
                $this->seriesAttributes($details, $category),
            );

            if (! $series->wasRecentlyCreated && $series->category !== $category) {
                $series->update(['category' => $category]);
            }

            foreach ($requestedSeasons as $seasonNumber) {
                $summary = $availableSeasons->get($seasonNumber);

                if (! is_array($summary)) {
                    continue;
                }

                $seasonDetails = $this->tmdb->season($series->tmdb_id, $seasonNumber);
                $season = SeriesSeason::query()->firstOrCreate(
                    ['series_id' => $series->getKey(), 'season_number' => $seasonNumber],
                    [
                        'tmdb_id' => $seasonDetails['tmdb_id'],
                        'name' => $seasonNumber === 0 ? 'Specials' : $seasonDetails['name'],
                        'overview' => $seasonDetails['overview'],
                        'poster_path' => $seasonDetails['poster_path'],
                        'air_date' => $seasonDetails['air_date'],
                        'episode_count' => count($seasonDetails['episodes']),
                        'metadata_version' => 1,
                        'metadata_snapshot' => $seasonDetails,
                    ],
                );

                foreach ($seasonDetails['episodes'] as $episode) {
                    $season->episodes()->firstOrCreate(
                        ['episode_number' => $episode['episode_number']],
                        [
                            'tmdb_id' => $episode['tmdb_id'],
                            'name' => $episode['name'],
                            'overview' => $episode['overview'],
                            'air_date' => $episode['air_date'],
                            'runtime_minutes' => $episode['runtime_minutes'],
                            'metadata_version' => 1,
                            'metadata_snapshot' => $episode,
                        ],
                    );
                }
            }

            return $series->refresh()->load('seasons.episodes.currentMediaFile');
        }, attempts: 3);
    }

    /** @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function seriesAttributes(array $details, SeriesCategory $category): array
    {
        return [
            'category' => $category,
            'name' => $details['name'],
            'original_name' => $details['original_name'],
            'first_air_date' => $details['first_air_date'],
            'first_air_year' => $details['first_air_year'],
            'overview' => $details['overview'],
            'poster_path' => $details['poster_path'],
            'original_language' => $details['original_language'],
            'external_ids' => $details['external_ids'],
            'episode_total' => $details['number_of_episodes'],
            'metadata_version' => 1,
            'metadata_snapshot' => $details,
        ];
    }
}
