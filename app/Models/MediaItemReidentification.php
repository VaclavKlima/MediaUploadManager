<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MediaItemReidentificationFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $media_item_id
 * @property int $actor_user_id
 * @property int|null $source_media_file_id
 * @property int|null $source_upload_id
 * @property array<string, mixed> $old_metadata_snapshot
 * @property array<string, mixed> $new_metadata_snapshot
 * @property string|null $disk_id
 * @property string|null $source_relative_path
 * @property string|null $destination_relative_path
 * @property int|null $size_bytes
 * @property int|null $device_id
 * @property int|null $inode_id
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property CarbonInterface $claimed_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 */
#[Fillable([
    'media_item_id', 'actor_user_id', 'source_media_file_id', 'source_upload_id',
    'old_metadata_snapshot', 'new_metadata_snapshot', 'disk_id', 'source_relative_path',
    'destination_relative_path', 'size_bytes', 'device_id', 'inode_id', 'status',
    'error_code', 'error_detail', 'claimed_at', 'completed_at', 'failed_at',
])]
class MediaItemReidentification extends Model
{
    /** @use HasFactory<MediaItemReidentificationFactory> */
    use HasFactory;

    /** @var list<string> */
    private const CLAIM_ATTRIBUTES = [
        'media_item_id', 'actor_user_id', 'source_media_file_id', 'source_upload_id',
        'old_metadata_snapshot', 'new_metadata_snapshot', 'disk_id', 'source_relative_path',
        'destination_relative_path', 'size_bytes', 'device_id', 'inode_id', 'claimed_at',
    ];

    /** @return BelongsTo<MediaItem, $this> */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function sourceMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'source_media_file_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            if ($operation->status !== 'pending'
                || ! $operation->getAttribute('claimed_at') instanceof CarbonInterface
            ) {
                throw new DomainException('A re-identification must begin with a durable pending claim.');
            }

            $hasPhysicalClaim = $operation->source_media_file_id !== null;
            $physicalValues = [
                $operation->disk_id, $operation->source_relative_path,
                $operation->destination_relative_path, $operation->size_bytes,
                $operation->device_id, $operation->inode_id,
            ];

            if ($hasPhysicalClaim !== collect($physicalValues)->every(fn (mixed $value): bool => $value !== null)) {
                throw new DomainException('A re-identification physical claim must be complete.');
            }
        });

        static::updating(function (self $operation): void {
            if ($operation->isDirty(self::CLAIM_ATTRIBUTES)) {
                throw new DomainException('A re-identification claim is immutable.');
            }

            if ($operation->getOriginal('completed_at') !== null && $operation->isDirty()) {
                throw new DomainException('A completed re-identification is immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_metadata_snapshot' => 'array',
            'new_metadata_snapshot' => 'array',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
