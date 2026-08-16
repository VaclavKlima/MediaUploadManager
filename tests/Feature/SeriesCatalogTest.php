<?php

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function addCatalogEpisodeFile(SeriesEpisode $episode, User $owner): MediaFile
{
    $upload = Upload::factory()->for($owner)->forSeriesEpisode($episode)->create([
        'target_relative_path' => "Catalog/episode-{$episode->id}.mkv",
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

it('requires authentication for the Shows catalog and details', function () {
    $series = Series::factory()->create();

    $this->get(route('series.index'))->assertRedirect(route('login'));
    $this->get(route('series.show', $series))->assertRedirect(route('login'));
});

it('lists every confirmed Show with compact coverage that excludes Specials', function () {
    $actor = User::factory()->create();
    $series = Series::factory()->create([
        'name' => 'The Expanse',
        'original_name' => 'The Expanse Original',
        'episode_total' => 2,
        'metadata_snapshot' => ['seasons' => [
            ['season_number' => 0, 'name' => 'Specials', 'episode_count' => 4],
            ['season_number' => 1, 'name' => 'Season 1', 'episode_count' => 2],
        ]],
    ]);
    $specials = SeriesSeason::factory()->for($series)->create(['season_number' => 0]);
    $season = SeriesSeason::factory()->for($series)->create(['season_number' => 1]);
    addCatalogEpisodeFile(SeriesEpisode::factory()->for($specials, 'season')->create(), $actor);
    addCatalogEpisodeFile(SeriesEpisode::factory()->for($season, 'season')->create(), $actor);
    SeriesEpisode::factory()->for($season, 'season')->create(['air_date' => today()->subDay()]);
    $empty = Series::factory()->create(['name' => 'Empty Show', 'episode_total' => 8]);

    $this->actingAs($actor)
        ->get(route('series.index', ['search' => 'Expanse', 'status' => 'missing', 'sort' => 'coverage']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('series/Index')
            ->where('filters', ['search' => 'Expanse', 'status' => 'missing', 'sort' => 'coverage'])
            ->where('series.per_page', 48)
            ->has('series.data', 1)
            ->where('series.data.0.id', $series->id)
            ->where('series.data.0.state', 'missing')
            ->where('series.data.0.coverage.seasons', ['available' => 1, 'total' => 1])
            ->where('series.data.0.coverage.episodes', ['available' => 1, 'total' => 2]));

    $this->actingAs($actor)
        ->get(route('series.index', ['status' => 'empty']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('series.data', 1)
            ->where('series.data.0.id', $empty->id));
});

it('renders the Movies-density responsive Shows grid and catalog controls', function () {
    $page = file_get_contents(resource_path('js/pages/series/Index.vue'));

    expect($page)
        ->toContain('grid-cols-2')
        ->toContain('sm:grid-cols-4')
        ->toContain('lg:grid-cols-6')
        ->toContain('2xl:grid-cols-8')
        ->toContain('Search title, original title, or TMDB ID')
        ->toContain('Missing aired')
        ->toContain('Best coverage')
        ->toContain('Actions for')
        ->toContain('item.coverage.seasons.available')
        ->toContain('item.coverage.episodes.available');
});
