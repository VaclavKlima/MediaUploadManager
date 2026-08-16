<?php

namespace Database\Factories;

use App\Enums\SeriesCategory;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Series> */
class SeriesFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-50 years', 'now');

        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 20_000_000),
            'category' => SeriesCategory::Tv,
            'name' => fake()->words(3, true),
            'original_name' => fake()->words(3, true),
            'first_air_date' => $date,
            'first_air_year' => (int) $date->format('Y'),
            'overview' => fake()->paragraph(),
            'poster_path' => '/'.fake()->lexify('????????').'.jpg',
            'original_language' => fake()->languageCode(),
            'external_ids' => ['imdb_id' => null, 'tvdb_id' => null],
            'episode_total' => fake()->numberBetween(1, 200),
            'metadata_version' => 1,
            'metadata_snapshot' => ['source' => 'tmdb'],
        ];
    }
}
