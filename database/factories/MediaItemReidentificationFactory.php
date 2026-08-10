<?php

namespace Database\Factories;

use App\Models\MediaItem;
use App\Models\MediaItemReidentification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaItemReidentification>
 */
class MediaItemReidentificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mediaItem = MediaItem::factory()->create();

        return [
            'media_item_id' => $mediaItem->id,
            'actor_user_id' => User::factory()->administrator(),
            'old_metadata_snapshot' => $mediaItem->only([
                'tmdb_id', 'imdb_id', 'title', 'original_title', 'release_date', 'release_year',
                'overview', 'poster_path', 'original_language', 'metadata_version', 'metadata_snapshot',
            ]),
            'new_metadata_snapshot' => MediaItem::factory()->make()->only([
                'tmdb_id', 'imdb_id', 'title', 'original_title', 'release_date', 'release_year',
                'overview', 'poster_path', 'original_language', 'metadata_version', 'metadata_snapshot',
            ]),
            'status' => 'pending',
            'claimed_at' => now(),
        ];
    }
}
