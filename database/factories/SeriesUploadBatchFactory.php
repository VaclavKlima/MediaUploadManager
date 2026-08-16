<?php

namespace Database\Factories;

use App\Enums\SeriesBatchStatus;
use App\Models\Series;
use App\Models\SeriesUploadBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SeriesUploadBatch> */
class SeriesUploadBatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $manifest = [['source_identity' => 'Season 01/Show.S01E01.mkv']];

        return [
            'uuid' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'series_id' => Series::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'manifest' => $manifest,
            'manifest_hash' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
            'disk_id' => 'series-1',
            'declared_bytes' => 1_000_000,
            'confirmed_bytes' => 0,
            'status' => SeriesBatchStatus::Pending,
        ];
    }
}
