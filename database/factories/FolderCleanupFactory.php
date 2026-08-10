<?php

namespace Database\Factories;

use App\Models\FolderCleanup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolderCleanup>
 */
class FolderCleanupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['is_administrator' => true]),
            'disk_id' => 'movies',
            'relative_folder' => 'Unsorted/old-folder',
            'status' => 'previewed',
            'manifest' => [],
            'manifest_hash' => hash('sha256', '[]'),
            'file_count' => 0,
            'total_size_bytes' => 0,
        ];
    }
}
