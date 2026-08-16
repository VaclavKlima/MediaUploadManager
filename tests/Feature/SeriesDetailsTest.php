<?php

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function addSeriesDetailsEpisodeFile(SeriesEpisode $episode, User $owner): MediaFile
{
    $upload = Upload::factory()->for($owner)->forSeriesEpisode($episode)->create([
        'target_relative_path' => "Details/episode-{$episode->id}.mkv",
    ]);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Completed->value,
        'confirmed_offset' => $upload->declared_size,
        'completed_at' => now(),
        'expires_at' => null,
    ]);
    $file = MediaFile::factory()->forUpload($upload->refresh())->create();
    $episode->update(['current_media_file_id' => $file->id]);

    return $file;
}

it('presents season lifecycle states custom titles and secret-free technical tags', function () {
    $owner = User::factory()->create();
    $series = Series::factory()->create([
        'name' => 'Foundation',
        'episode_total' => 4,
        'metadata_snapshot' => ['seasons' => [
            ['season_number' => 0, 'name' => 'Specials', 'episode_count' => 1],
            ['season_number' => 1, 'name' => 'Season 1', 'episode_count' => 4],
        ]],
    ]);
    SeriesSeason::factory()->for($series)->create(['season_number' => 0]);
    $season = SeriesSeason::factory()->for($series)->create(['season_number' => 1]);
    $available = SeriesEpisode::factory()->for($season, 'season')->create([
        'episode_number' => 1,
        'name' => 'The Emperor’s Peace',
        'custom_name' => 'Emperor’s Peace',
        'air_date' => today()->subYear(),
    ]);
    $file = addSeriesDetailsEpisodeFile($available, $owner);
    MediaFile::query()->whereKey($file)->update([
        'video_metadata' => [['codec' => 'hevc', 'width' => 3840, 'dynamic_range' => 'hdr10']],
        'audio_metadata' => [['codec' => 'eac3', 'channels' => 6]],
        'duration_milliseconds' => 3_600_000,
    ]);
    SeriesEpisode::factory()->for($season, 'season')->create(['episode_number' => 2, 'air_date' => today()->subDay()]);
    SeriesEpisode::factory()->for($season, 'season')->create(['episode_number' => 3, 'air_date' => today()->addDay()]);
    SeriesEpisode::factory()->for($season, 'season')->create(['episode_number' => 4, 'air_date' => null]);

    $this->actingAs($owner)
        ->get(route('series.show', ['series' => $series, 'season' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('series/Show')
            ->where('show.name', 'Foundation')
            ->where('show.coverage.seasons', ['available' => 1, 'total' => 1])
            ->where('show.selected_season_hydrated', true)
            ->where('show.selected_season.episodes.0.name', 'Emperor’s Peace')
            ->where('show.selected_season.episodes.0.tmdb_name', 'The Emperor’s Peace')
            ->where('show.selected_season.episodes.0.state', 'available')
            ->where('show.selected_season.episodes.0.actions.can_rename', true)
            ->where('show.selected_season.episodes.0.current_file.technical_tags.0', ['kind' => 'quality', 'label' => '4K · HDR10'])
            ->where('show.selected_season.episodes.1.state', 'missing')
            ->where('show.selected_season.episodes.2.state', 'upcoming')
            ->where('show.selected_season.episodes.3.state', 'unscheduled')
            ->missing('show.selected_season.episodes.0.current_file.video_metadata')
            ->missing('show.selected_season.episodes.0.current_file.probe_snapshot'));
});

it('selects an unhydrated deep-linked season without losing its page state', function () {
    $series = Series::factory()->create(['metadata_snapshot' => ['seasons' => [
        ['season_number' => 1, 'name' => 'Season 1', 'episode_count' => 10],
        ['season_number' => 2, 'name' => 'Season 2', 'episode_count' => 8],
    ]]]);

    $this->actingAs(User::factory()->create())
        ->get(route('series.show', ['series' => $series, 'season' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('show.selected_season_number', 2)
            ->where('show.selected_season_hydrated', false)
            ->where('show.selected_season', null));
});

it('provides responsive season navigation hydration guards and destructive workflows', function () {
    $page = file_get_contents(resource_path('js/pages/series/Show.vue'));

    expect($page)
        ->toContain('lg:grid-cols-[14rem_minmax(0,1fr)]')
        ->toContain('aria-label="Choose season"')
        ->toContain('Loading')
        ->toContain('season episodes…')
        ->toContain('hydrationRevision')
        ->toContain("only: ['show']")
        ->toContain('previewEpisodeRename.url')
        ->toContain('rename_confirmed')
        ->toContain('confirmation_name')
        ->toContain('Artwork, subtitles, NFO files')
        ->toContain('.technical_tags');
});
