<?php

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the Series domain and generalized transport schema', function () {
    expect(Schema::hasColumns('series', ['tmdb_id', 'category', 'metadata_snapshot', 'home_disk_id']))->toBeTrue()
        ->and(Schema::hasColumns('series_seasons', ['series_id', 'season_number', 'metadata_snapshot']))->toBeTrue()
        ->and(Schema::hasColumns('series_episodes', ['series_season_id', 'episode_number', 'current_media_file_id', 'custom_name']))->toBeTrue()
        ->and(Schema::hasColumns('series_upload_batches', ['uuid', 'manifest_hash', 'declared_bytes', 'status']))->toBeTrue()
        ->and(Schema::hasColumns('uploads', ['media_item_id', 'series_episode_id', 'series_upload_batch_id', 'batch_position', 'root_kind']))->toBeTrue()
        ->and(Schema::hasColumns('media_files', ['media_item_id', 'series_episode_id', 'root_kind']))->toBeTrue()
        ->and(Schema::hasColumns('episode_rename_operations', ['series_episode_id', 'source_relative_path', 'destination_relative_path', 'status']))->toBeTrue()
        ->and(Schema::hasColumns('series_deletion_operations', ['series_id', 'scope_type', 'manifest', 'status']))->toBeTrue();
});

it('persists Specials normally and keeps a Series home disk immutable', function () {
    $series = Series::factory()->create(['category' => SeriesCategory::Anime, 'home_disk_id' => 'nas_a']);
    $season = SeriesSeason::factory()->for($series)->create(['season_number' => 0, 'name' => 'Specials']);
    $episode = SeriesEpisode::factory()->for($season, 'season')->create(['episode_number' => 101]);

    expect($series->category)->toBe(SeriesCategory::Anime)
        ->and($season->displayName())->toBe('Specials')
        ->and($episode->season->series->is($series))->toBeTrue();

    $series->update(['home_disk_id' => 'nas_b']);
})->throws(DomainException::class);

it('requires exactly one Movie or Series episode subject at the database boundary', function (string $kind) {
    $attributes = Upload::factory()->raw();

    if ($kind === 'neither') {
        $attributes['media_item_id'] = null;
        $attributes['series_episode_id'] = null;
    } else {
        $attributes['series_episode_id'] = SeriesEpisode::factory()->create()->id;
    }

    Upload::withoutEvents(fn () => Upload::query()->insert($attributes));
})->with(['neither', 'both'])->throws(QueryException::class);

it('separates active path identity by root kind', function () {
    $movie = MediaItem::factory()->create();
    $movieUpload = Upload::factory()->for($movie)->create(['disk_id' => 'nas', 'target_relative_path' => 'same/path.mkv']);
    $movieFile = MediaFile::factory()->forUpload($movieUpload)->create();
    $episode = SeriesEpisode::factory()->create();
    $seriesUpload = Upload::factory()->forSeriesEpisode($episode)->create(['disk_id' => 'nas', 'target_relative_path' => 'same/path.mkv']);
    $seriesFile = MediaFile::factory()->forUpload($seriesUpload)->create();

    expect($movieFile->root_kind)->toBe(MediaRootKind::Movies)
        ->and($seriesFile->root_kind)->toBe(MediaRootKind::Series)
        ->and($movieFile->active_path_key)->not->toBe($seriesFile->active_path_key);
});
