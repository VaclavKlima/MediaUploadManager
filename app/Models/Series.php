<?php

namespace App\Models;

use App\Enums\SeriesCategory;
use Carbon\CarbonInterface;
use Database\Factories\SeriesFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $id
 * @property int $tmdb_id
 * @property SeriesCategory $category
 * @property string $name
 * @property string|null $original_name
 * @property CarbonInterface|null $first_air_date
 * @property int|null $first_air_year
 * @property string|null $overview
 * @property string|null $poster_path
 * @property string|null $original_language
 * @property array<string, mixed> $external_ids
 * @property int $episode_total
 * @property int $metadata_version
 * @property array<string, mixed> $metadata_snapshot
 * @property string|null $home_disk_id
 * @property CarbonInterface|null $last_episode_finalized_at
 * @property-read Collection<int, SeriesSeason> $seasons
 * @property-read Collection<int, SeriesEpisode> $episodes
 * @property int $uploaded_episode_count
 * @property int $available_episode_count
 * @property int $available_season_count
 * @property int $hydrated_numbered_season_count
 * @property int $missing_aired_episode_count
 */
#[Fillable([
    'tmdb_id', 'category', 'name', 'original_name', 'first_air_date', 'first_air_year',
    'overview', 'poster_path', 'original_language', 'external_ids', 'episode_total',
    'metadata_version', 'metadata_snapshot', 'home_disk_id', 'last_episode_finalized_at',
])]
class Series extends Model
{
    /** @use HasFactory<SeriesFactory> */
    use HasFactory;

    /** @return HasMany<SeriesSeason, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(SeriesSeason::class)->orderBy('season_number');
    }

    /** @return HasMany<SeriesUploadBatch, $this> */
    public function uploadBatches(): HasMany
    {
        return $this->hasMany(SeriesUploadBatch::class);
    }

    /** @return HasManyThrough<SeriesEpisode, SeriesSeason, $this> */
    public function episodes(): HasManyThrough
    {
        return $this->hasManyThrough(SeriesEpisode::class, SeriesSeason::class);
    }

    /** @return HasMany<SeriesDeletionOperation, $this> */
    public function deletionOperations(): HasMany
    {
        return $this->hasMany(SeriesDeletionOperation::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $series): void {
            if ($series->getOriginal('home_disk_id') !== null && $series->isDirty('home_disk_id')) {
                throw new DomainException('A Series home disk is immutable once assigned.');
            }

            if ($series->isDirty('tmdb_id')) {
                throw new DomainException('A Series TMDB identity is immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => SeriesCategory::class,
            'first_air_date' => 'date',
            'external_ids' => 'array',
            'metadata_snapshot' => 'array',
            'last_episode_finalized_at' => 'datetime',
        ];
    }
}
