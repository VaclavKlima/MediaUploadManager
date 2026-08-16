<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SeriesEpisodeFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $series_season_id
 * @property int $tmdb_id
 * @property int $episode_number
 * @property string $name
 * @property string|null $custom_name
 * @property string|null $overview
 * @property CarbonInterface|null $air_date
 * @property int|null $runtime_minutes
 * @property int $metadata_version
 * @property array<string, mixed> $metadata_snapshot
 * @property int|null $current_media_file_id
 * @property int $active_uploads_count
 * @property int $failed_uploads_count
 * @property-read SeriesSeason $season
 * @property-read MediaFile|null $currentMediaFile
 */
#[Fillable([
    'series_season_id', 'tmdb_id', 'episode_number', 'name', 'custom_name', 'overview', 'air_date',
    'runtime_minutes', 'metadata_version', 'metadata_snapshot', 'current_media_file_id',
])]
class SeriesEpisode extends Model
{
    /** @use HasFactory<SeriesEpisodeFactory> */
    use HasFactory;

    /** @return BelongsTo<SeriesSeason, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(SeriesSeason::class, 'series_season_id');
    }

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

    /** @return HasMany<EpisodeRenameOperation, $this> */
    public function renameOperations(): HasMany
    {
        return $this->hasMany(EpisodeRenameOperation::class);
    }

    public function displayName(): string
    {
        return $this->custom_name ?? $this->name;
    }

    protected static function booted(): void
    {
        static::updating(function (self $episode): void {
            if ($episode->isDirty(['series_season_id', 'episode_number', 'tmdb_id'])) {
                throw new DomainException('A Series episode identity is immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['air_date' => 'date', 'metadata_snapshot' => 'array'];
    }
}
