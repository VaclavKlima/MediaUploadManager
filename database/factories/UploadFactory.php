<?php

namespace Database\Factories;

use App\Enums\UploadStatus;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid7();
        $size = fake()->numberBetween(1_000_000, 50_000_000_000);

        return [
            'uuid' => $uuid,
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'media_item_id' => MediaItem::factory(),
            'status' => UploadStatus::Pending,
            'disk_id' => 'movies-'.fake()->numberBetween(1, 3),
            'target_relative_path' => 'Movies/'.fake()->slug(3).'/'.fake()->slug(3).'.mkv',
            'staging_relative_path' => '.media-upload-manager/incoming/'.$uuid.'.part',
            'original_filename' => fake()->slug(3).'.mkv',
            'extension' => 'mkv',
            'declared_size' => $size,
            'confirmed_offset' => 0,
            'last_modified_milliseconds' => fake()->numberBetween(1_600_000_000_000, 1_900_000_000_000),
            'fingerprint_first_sha256' => hash('sha256', fake()->uuid().'first'),
            'fingerprint_last_sha256' => hash('sha256', fake()->uuid().'last'),
            'token_hash' => hash('sha256', Str::random(64)),
            'token_abilities' => ['tus:create', 'tus:read', 'tus:write', 'tus:terminate'],
            'token_expires_at' => now()->addHour(),
            'last_activity_at' => now(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function status(UploadStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }
}
