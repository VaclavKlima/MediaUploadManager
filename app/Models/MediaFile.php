<?php

namespace App\Models;

use App\ValueObjects\ByteCount;
use App\ValueObjects\RelativeMediaPath;
use Carbon\CarbonInterface;
use Database\Factories\MediaFileFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $media_item_id
 * @property int|null $source_upload_id
 * @property int|null $imported_by_user_id
 * @property array<string, mixed>|null $import_provenance
 * @property string $disk_id
 * @property string $relative_path
 * @property string|null $active_path_key
 * @property int $size_bytes
 * @property string $container
 * @property int $duration_milliseconds
 * @property array<string, mixed> $video_metadata
 * @property array<string, mixed> $audio_metadata
 * @property array<string, mixed> $probe_snapshot
 * @property CarbonInterface $finalized_at
 * @property int|null $replaced_by_media_file_id
 * @property CarbonInterface|null $replaced_at
 * @property CarbonInterface|null $removed_at
 * @property string|null $removal_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'media_item_id',
    'source_upload_id',
    'imported_by_user_id',
    'import_provenance',
    'disk_id',
    'relative_path',
    'active_path_key',
    'size_bytes',
    'container',
    'duration_milliseconds',
    'video_metadata',
    'audio_metadata',
    'probe_snapshot',
    'finalized_at',
    'replaced_by_media_file_id',
    'replaced_at',
    'removed_at',
    'removal_reason',
])]
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory;

    /** @var list<string> */
    private const IMMUTABLE_ATTRIBUTES = [
        'media_item_id',
        'source_upload_id',
        'imported_by_user_id',
        'import_provenance',
        'disk_id',
        'relative_path',
        'size_bytes',
        'container',
        'duration_milliseconds',
        'video_metadata',
        'audio_metadata',
        'probe_snapshot',
        'finalized_at',
    ];

    /** @return BelongsTo<MediaItem, $this> */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** @return BelongsTo<Upload, $this> */
    public function sourceUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'source_upload_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_media_file_id');
    }

    /** @return HasOne<MediaFile, $this> */
    public function replacedFile(): HasOne
    {
        return $this->hasOne(self::class, 'replaced_by_media_file_id');
    }

    /** @return HasOne<MediaItem, $this> */
    public function currentForMediaItem(): HasOne
    {
        return $this->hasOne(MediaItem::class, 'current_media_file_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $mediaFile): void {
            $mediaFile->active_path_key ??= self::activePathKey(
                $mediaFile->disk_id,
                $mediaFile->relative_path,
            );
            $mediaFile->validatePhysicalMetadata();
        });

        static::updating(function (self $mediaFile): void {
            if (($mediaFile->isDirty('replaced_at') && $mediaFile->replaced_at !== null)
                || ($mediaFile->isDirty('removed_at') && $mediaFile->removed_at !== null)
            ) {
                $mediaFile->active_path_key = null;
            }

            if ($mediaFile->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new DomainException('Physical media-file metadata is immutable.');
            }

            if ($mediaFile->getOriginal('replaced_by_media_file_id') !== null && $mediaFile->isDirty('replaced_by_media_file_id')) {
                throw new DomainException('A media-file replacement link is write-once.');
            }

            if ($mediaFile->isDirty('active_path_key')
                && ($mediaFile->getOriginal('active_path_key') === null || $mediaFile->active_path_key !== null)
            ) {
                throw new DomainException('An active media path may only be released once.');
            }

            $mediaFile->validateReplacementLink();
        });
    }

    public static function activePathKey(string $diskId, string $relativePath): string
    {
        return hash('sha256', $diskId."\0".$relativePath);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'video_metadata' => 'array',
            'audio_metadata' => 'array',
            'probe_snapshot' => 'array',
            'import_provenance' => 'array',
            'finalized_at' => 'datetime',
            'replaced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    private function validatePhysicalMetadata(): void
    {
        new RelativeMediaPath($this->relative_path);
        new ByteCount($this->size_bytes);
        new ByteCount($this->duration_milliseconds);

        if ($this->source_upload_id !== null) {
            $uploadBelongsToMovie = Upload::query()
                ->whereKey($this->source_upload_id)
                ->where('media_item_id', $this->media_item_id)
                ->exists();

            if (! $uploadBelongsToMovie) {
                throw new DomainException('The source upload and media file must belong to the same movie.');
            }
        } elseif ($this->imported_by_user_id === null || $this->import_provenance === null) {
            throw new DomainException('An imported media file requires administrator provenance.');
        }

        $this->validateReplacementLink();
    }

    private function validateReplacementLink(): void
    {
        if ($this->replaced_by_media_file_id === null) {
            return;
        }

        if ($this->exists && $this->replaced_by_media_file_id === $this->getKey()) {
            throw new DomainException('A media file cannot replace itself.');
        }

        $replacementBelongsToMovie = self::query()
            ->whereKey($this->replaced_by_media_file_id)
            ->where('media_item_id', $this->media_item_id)
            ->exists();

        if (! $replacementBelongsToMovie) {
            throw new DomainException('A replacement file must belong to the same movie.');
        }
    }
}
