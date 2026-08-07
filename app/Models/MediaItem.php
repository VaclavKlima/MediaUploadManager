<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MediaItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tmdb_id
 * @property string|null $imdb_id
 * @property string $title
 * @property string|null $original_title
 * @property CarbonInterface|null $release_date
 * @property int|null $release_year
 * @property string|null $overview
 * @property string|null $poster_path
 * @property string|null $original_language
 * @property int $metadata_version
 * @property array<string, mixed> $metadata_snapshot
 * @property int|null $current_media_file_id
 * @property array<string, mixed>|null $deletion_claim
 * @property CarbonInterface|null $deletion_requested_at
 * @property int $uploads_count
 * @property int $active_uploads_count
 * @property int $failed_uploads_count
 * @property int $other_user_uploads_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'tmdb_id',
    'imdb_id',
    'title',
    'original_title',
    'release_date',
    'release_year',
    'overview',
    'poster_path',
    'original_language',
    'metadata_version',
    'metadata_snapshot',
    'current_media_file_id',
    'deletion_claim',
    'deletion_requested_at',
])]
#[Hidden(['deletion_claim'])]
class MediaItem extends Model
{
    /** @use HasFactory<MediaItemFactory> */
    use HasFactory;

    /** @var list<string> */
    private const IMMUTABLE_ATTRIBUTES = [
        'tmdb_id',
        'imdb_id',
        'title',
        'original_title',
        'release_date',
        'release_year',
        'overview',
        'poster_path',
        'original_language',
        'metadata_version',
        'metadata_snapshot',
    ];

    /** @return BelongsTo<MediaFile, $this> */
    public function currentMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'current_media_file_id');
    }

    /** @return HasMany<MediaFile, $this> */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $mediaItem): void {
            $mediaItem->validateIdentity();

            if ($mediaItem->current_media_file_id !== null) {
                throw new DomainException('A new movie cannot start with a current primary file.');
            }
        });

        static::updating(function (self $mediaItem): void {
            if ($mediaItem->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new DomainException('Movie identity and metadata snapshots are immutable.');
            }

            if ($mediaItem->isDirty('current_media_file_id')) {
                $mediaItem->validateCurrentMediaFile();
            }

            if (($mediaItem->getOriginal('deletion_claim') !== null && $mediaItem->isDirty('deletion_claim'))
                || ($mediaItem->getOriginal('deletion_requested_at') !== null && $mediaItem->isDirty('deletion_requested_at'))
            ) {
                throw new DomainException('A movie deletion claim is write-once.');
            }

            if (($mediaItem->deletion_claim === null) !== ($mediaItem->deletion_requested_at === null)) {
                throw new DomainException('A movie deletion claim requires its request timestamp.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'metadata_snapshot' => 'array',
            'deletion_claim' => 'array',
            'deletion_requested_at' => 'datetime',
        ];
    }

    private function validateIdentity(): void
    {
        if ($this->tmdb_id < 1) {
            throw new DomainException('A TMDB identity must be a positive integer.');
        }

        if ($this->imdb_id !== null && preg_match('/\Att[0-9]{7,12}\z/', $this->imdb_id) !== 1) {
            throw new DomainException('An IMDb identity must use the canonical tt-number format.');
        }

        if ($this->title === '' || $this->metadata_version < 1) {
            throw new DomainException('A movie requires a title and a positive metadata version.');
        }
    }

    private function validateCurrentMediaFile(): void
    {
        if ($this->current_media_file_id === null) {
            return;
        }

        $belongsToMovie = MediaFile::query()
            ->whereKey($this->current_media_file_id)
            ->where('media_item_id', $this->getKey())
            ->exists();

        if (! $belongsToMovie) {
            throw new DomainException('The current primary file must belong to this movie.');
        }
    }
}
