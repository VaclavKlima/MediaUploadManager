<?php

namespace Database\Factories;

use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeriesEpisode> */
class SeriesEpisodeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'series_season_id' => SeriesSeason::factory(),
            'tmdb_id' => fake()->unique()->numberBetween(1, 20_000_000),
            'episode_number' => fake()->numberBetween(1, 50),
            'name' => fake()->sentence(3),
            'overview' => fake()->paragraph(),
            'air_date' => fake()->date(),
            'runtime_minutes' => fake()->numberBetween(10, 120),
            'metadata_version' => 1,
            'metadata_snapshot' => ['source' => 'tmdb'],
        ];
    }
}
