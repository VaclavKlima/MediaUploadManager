<?php

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaFile>
 */
class MediaFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $upload = Upload::factory()->create();

        return [
            'media_item_id' => $upload->media_item_id,
            'source_upload_id' => $upload->id,
            'disk_id' => $upload->disk_id,
            'relative_path' => $upload->target_relative_path,
            'size_bytes' => $upload->declared_size,
            'container' => 'matroska,webm',
            'duration_milliseconds' => fake()->numberBetween(60_000, 14_400_000),
            'video_metadata' => [
                'codec' => 'hevc',
                'width' => 3840,
                'height' => 2160,
            ],
            'audio_metadata' => [
                'codec' => 'aac',
                'channels' => 6,
            ],
            'probe_snapshot' => [
                'format' => ['format_name' => 'matroska,webm'],
            ],
            'finalized_at' => now(),
        ];
    }

    public function forUpload(Upload $upload): static
    {
        return $this->state(fn (): array => [
            'media_item_id' => $upload->media_item_id,
            'source_upload_id' => $upload->id,
            'disk_id' => $upload->disk_id,
            'relative_path' => $upload->target_relative_path,
            'size_bytes' => $upload->declared_size,
        ]);
    }
}
