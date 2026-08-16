<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SeriesSeasonFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $series_id
 * @property int $tmdb_id
 * @property int $season_number
 * @property string $name
 * @property string|null $overview
 * @property string|null $poster_path
 * @property CarbonInterface|null $air_date
 * @property int $episode_count
 * @property int $metadata_version
 * @property array<string, mixed> $metadata_snapshot
 * @property-read Series $series
 * @property-read Collection<int, SeriesEpisode> $episodes
 */
#[Fillable([
    'series_id', 'tmdb_id', 'season_number', 'name', 'overview', 'poster_path', 'air_date',
    'episode_count', 'metadata_version', 'metadata_snapshot',
])]
class SeriesSeason extends Model
{
    /** @use HasFactory<SeriesSeasonFactory> */
    use HasFactory;

    /** @return BelongsTo<Series, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /** @return HasMany<SeriesEpisode, $this> */
    public function episodes(): HasMany
    {
        return $this->hasMany(SeriesEpisode::class)->orderBy('episode_number');
    }

    public function displayName(): string
    {
        return $this->season_number === 0 ? 'Specials' : 'Season '.$this->season_number;
    }

    protected static function booted(): void
    {
        static::updating(function (self $season): void {
            if ($season->isDirty(['series_id', 'season_number', 'tmdb_id'])) {
                throw new DomainException('A Series season identity is immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['air_date' => 'date', 'metadata_snapshot' => 'array'];
    }
}
