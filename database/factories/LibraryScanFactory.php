<?php

namespace Database\Factories;

use App\Models\LibraryScan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryScan>
 */
class LibraryScanFactory extends Factory
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
            'status' => 'completed',
            'disk_statuses' => [],
            'discovered_count' => 0,
            'missing_count' => 0,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }
}
