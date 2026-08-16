<?php

namespace Database\Factories;

use App\Models\Series;
use App\Models\SeriesSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeriesSeason> */
class SeriesSeasonFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = fake()->numberBetween(0, 20);

        return [
            'series_id' => Series::factory(),
            'tmdb_id' => fake()->unique()->numberBetween(1, 20_000_000),
            'season_number' => $number,
            'name' => $number === 0 ? 'Specials' : 'Season '.$number,
            'overview' => fake()->paragraph(),
            'poster_path' => '/'.fake()->lexify('????????').'.jpg',
            'air_date' => fake()->date(),
            'episode_count' => 10,
            'metadata_version' => 1,
            'metadata_snapshot' => ['source' => 'tmdb'],
        ];
    }
}
