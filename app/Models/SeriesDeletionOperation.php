<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $actor_user_id
 * @property int $series_id
 * @property string $scope_type
 * @property int $scope_id
 * @property string $series_name
 * @property string $status
 * @property array<int, array<string, int|string|null>> $manifest
 * @property string $manifest_hash
 * @property int $file_count
 * @property int $total_size_bytes
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property CarbonInterface $confirmed_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 */
#[Fillable([
    'actor_user_id', 'series_id', 'scope_type', 'scope_id', 'series_name', 'status',
    'manifest', 'manifest_hash', 'file_count', 'total_size_bytes', 'error_code',
    'error_detail', 'confirmed_at', 'completed_at', 'failed_at',
])]
class SeriesDeletionOperation extends Model
{
    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
