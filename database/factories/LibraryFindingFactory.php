<?php

namespace Database\Factories;

use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryFinding>
 */
class LibraryFindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug(3).'.mkv';

        return [
            'library_scan_id' => LibraryScan::factory(),
            'disk_id' => 'movies',
            'relative_path' => 'Unsorted/'.$filename,
            'source_folder' => 'Unsorted',
            'source_filename' => $filename,
            'size_bytes' => fake()->numberBetween(1_000_000, 10_000_000_000),
            'device_id' => fake()->numberBetween(1, 10_000),
            'inode_id' => fake()->numberBetween(1, 10_000_000),
            'kind' => 'discovered',
            'status' => 'needs_identification',
        ];
    }
}
