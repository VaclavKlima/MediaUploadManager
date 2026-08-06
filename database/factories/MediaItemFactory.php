<?php

namespace Database\Factories;

use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $releaseDate = fake()->dateTimeBetween('-80 years', 'now');

        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 20_000_000),
            'imdb_id' => 'tt'.fake()->unique()->numerify('########'),
            'title' => fake()->sentence(3),
            'original_title' => fake()->sentence(3),
            'release_date' => $releaseDate,
            'release_year' => (int) $releaseDate->format('Y'),
            'overview' => fake()->paragraph(),
            'poster_path' => '/'.fake()->lexify('????????').'.jpg',
            'original_language' => fake()->languageCode(),
            'metadata_version' => 1,
            'metadata_snapshot' => [
                'source' => 'tmdb',
                'captured' => true,
            ],
        ];
    }
}
