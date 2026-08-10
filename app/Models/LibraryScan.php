<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\LibraryScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property array<string, mixed>|null $disk_statuses
 * @property int $discovered_count
 * @property int $missing_count
 * @property string|null $error_detail
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 */
#[Fillable([
    'user_id',
    'status',
    'disk_statuses',
    'discovered_count',
    'missing_count',
    'error_detail',
    'started_at',
    'completed_at',
])]
class LibraryScan extends Model
{
    /** @use HasFactory<LibraryScanFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LibraryFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(LibraryFinding::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'disk_statuses' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
