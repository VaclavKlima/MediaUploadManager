<?php

namespace App\Models;

use App\Enums\SeriesBatchStatus;
use Carbon\CarbonInterface;
use Database\Factories\SeriesUploadBatchFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $series_id
 * @property string $idempotency_key
 * @property list<array<string, mixed>> $manifest
 * @property string $manifest_hash
 * @property string $disk_id
 * @property int $declared_bytes
 * @property int $confirmed_bytes
 * @property SeriesBatchStatus $status
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $paused_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $cancelled_at
 * @property-read User $user
 * @property-read Series $series
 * @property-read Collection<int, Upload> $uploads
 */
#[Fillable([
    'uuid', 'user_id', 'series_id', 'idempotency_key', 'manifest', 'manifest_hash', 'disk_id',
    'declared_bytes', 'confirmed_bytes', 'status', 'started_at', 'paused_at', 'completed_at', 'cancelled_at',
])]
class SeriesUploadBatch extends Model
{
    /** @use HasFactory<SeriesUploadBatchFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Series, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class)->orderBy('batch_position');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->uuid ??= (string) Str::uuid7();
        });

        static::updating(function (self $batch): void {
            if ($batch->isDirty(['uuid', 'user_id', 'series_id', 'idempotency_key', 'manifest', 'manifest_hash', 'disk_id', 'declared_bytes'])) {
                throw new DomainException('Series batch admission attributes are immutable.');
            }

            $originalConfirmedBytes = $batch->getRawOriginal('confirmed_bytes');

            if ((! is_int($originalConfirmedBytes) && ! is_string($originalConfirmedBytes))
                || $batch->confirmed_bytes < (int) $originalConfirmedBytes
            ) {
                throw new DomainException('Series batch progress cannot move backwards.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manifest' => 'array', 'status' => SeriesBatchStatus::class, 'started_at' => 'datetime',
            'paused_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }
}
