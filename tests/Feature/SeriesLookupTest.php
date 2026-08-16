<?php

use App\Actions\Series\CreateOrUpdateSeries;
use App\Enums\SeriesCategory;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.tmdb', [
        'token' => 'test-tmdb-token',
        'language' => 'en-US',
        'base_url' => 'https://api.themoviedb.org/3',
        'cache_ttl' => 86400,
        'connect_timeout' => 1,
        'request_timeout' => 1,
    ]);
    Cache::clear();
    Http::preventStrayRequests();
});

function seriesSuggestionPayload(int $id = 1396, string $name = 'Breaking Bad', string $firstAirDate = '2008-01-20'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'original_name' => $name,
        'first_air_date' => $firstAirDate,
        'overview' => 'A show overview.',
        'poster_path' => '/poster.jpg',
        'original_language' => 'en',
    ];
}

it('requires authentication and validates metadata-only suggestion names', function () {
    $this->get(route('series.suggestions', ['source_name' => 'Show.S01E01.mkv']))
        ->assertRedirect(route('login'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('series.suggestions', ['source_name' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('source_name');

    $this->actingAs($user)
        ->getJson(route('series.suggestions', ['source_name' => '/private/videos/Show.S01E01.mkv']))
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('source_name');
});

it('suggests deduplicated shows from episode metadata and caches the TV search', function () {
    Http::fake([
        'api.themoviedb.org/3/search/tv*' => Http::response(['results' => [
            seriesSuggestionPayload(),
            seriesSuggestionPayload(),
        ]]),
    ]);
    $user = User::factory()->create();
    $parameters = ['source_name' => 'Breaking.Bad.2008.S01E01.1080p.WEB-DL.x265.mkv'];

    $this->actingAs($user)
        ->getJson(route('series.suggestions', $parameters))
        ->assertSuccessful()
        ->assertJsonPath('meta.source', 'filename')
        ->assertJsonPath('meta.parsed.title', 'Breaking Bad')
        ->assertJsonPath('meta.parsed.year', 2008)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tmdb_id', 1396)
        ->assertJsonMissingPath('meta.parsed.filename');

    $this->actingAs($user)
        ->getJson(route('series.suggestions', ['source_name' => 'Breaking Bad (2008)']))
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['query'] === 'Breaking Bad'
        && $request['first_air_date_year'] === 2008
        && ! isset($request['source_name']));
});

it('falls back only after empty results and issues at most three TV searches', function () {
    Http::fake(fn (Request $request) => Http::response(['results' => $request['query'] === 'Amélie' && ! isset($request['first_air_date_year'])
            ? [seriesSuggestionPayload(777, 'Amélie', '2001-01-01')]
            : [],
    ]));

    $this->actingAs(User::factory()->create())
        ->getJson(route('series.suggestions', [
            'source_name' => 'Amélie: Le fabuleux destin.2001.S01E01.mkv',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.tmdb_id', 777);

    Http::assertSentCount(3);
    $queries = Http::recorded()
        ->map(fn (array $recorded): string => $recorded[0]['query'])
        ->all();

    expect($queries)->toBe([
        'Amélie: Le fabuleux destin',
        'Amélie: Le fabuleux destin',
        'Amélie',
    ]);
});

it('does not run suggestion fallbacks after the first non-empty result', function () {
    Http::fake([
        'api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [seriesSuggestionPayload()],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('series.suggestions', [
            'source_name' => 'Breaking.Bad.2008.S01E01.mkv',
        ]))
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('returns a safe error when show suggestions are unavailable', function () {
    Http::fake([
        'api.themoviedb.org/3/search/tv*' => Http::response([
            'private' => 'upstream-only detail',
        ], 503),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('series.suggestions', [
            'source_name' => 'Breaking.Bad.S01E01.mkv',
        ]))
        ->assertServiceUnavailable();

    expect($response->getContent())
        ->not->toContain('upstream-only detail')
        ->not->toContain('test-tmdb-token');
});

it('searches TV Series with normalized metadata', function () {
    Http::fake([
        'api.themoviedb.org/3/search/tv*' => Http::response(['results' => [[
            'id' => 1396,
            'name' => 'Breaking Bad',
            'original_name' => 'Breaking Bad',
            'first_air_date' => '2008-01-20',
            'overview' => 'A chemistry teacher changes course.',
            'poster_path' => '/poster.jpg',
            'original_language' => 'en',
        ]]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('series.search', ['query' => ' Breaking Bad ', 'year' => 2008]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.tmdb_id', 1396)
        ->assertJsonPath('data.0.first_air_year', 2008)
        ->assertJsonPath('data.0.poster_url', 'https://image.tmdb.org/t/p/w500/poster.jpg');

    Http::assertSent(fn (Request $request): bool => $request['query'] === 'Breaking Bad'
        && $request['first_air_date_year'] === 2008
        && $request['language'] === 'en-US');
});

it('looks up an exact show by numeric TMDB ID', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'id' => 1396, 'imdb_id' => 'tt0903747', 'tvdb_id' => 81189,
        ]),
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            ...seriesSuggestionPayload(),
            'number_of_episodes' => 62,
            'seasons' => [],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('series.tmdb.show', ['tmdbId' => 1396]))
        ->assertSuccessful()
        ->assertJsonPath('data.tmdb_id', 1396)
        ->assertJsonPath('data.name', 'Breaking Bad')
        ->assertJsonPath('data.poster_url', 'https://image.tmdb.org/t/p/w500/poster.jpg');
});

it('looks up season episodes without mutating the Show catalog', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/season/0*' => Http::response([
            'id' => 3627,
            'season_number' => 0,
            'name' => 'Specials',
            'overview' => '',
            'poster_path' => null,
            'air_date' => '2009-02-17',
            'episodes' => [[
                'id' => 62085,
                'season_number' => 0,
                'episode_number' => 1,
                'name' => 'Good Cop / Bad Cop',
                'overview' => 'A special.',
                'air_date' => '2009-02-17',
                'runtime' => 5,
            ]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('series.tmdb.seasons.show', ['tmdbId' => 1396, 'seasonNumber' => 0]))
        ->assertSuccessful()
        ->assertJsonPath('data.season_number', 0)
        ->assertJsonPath('data.episodes.0.tmdb_id', 62085)
        ->assertJsonPath('data.episodes.0.episode_number', 1);

    expect(Series::query()->count())->toBe(0)
        ->and(SeriesSeason::query()->count())->toBe(0)
        ->and(SeriesEpisode::query()->count())->toBe(0);
});

it('confirms TV and Anime categories and hydrates a real TMDB Specials episode', function (string $category) {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'id' => 1396, 'imdb_id' => 'tt0903747', 'tvdb_id' => 81189,
        ]),
        'api.themoviedb.org/3/tv/1396/season/0*' => Http::response([
            'id' => 3627,
            'season_number' => 0,
            'name' => 'Specials',
            'overview' => '',
            'poster_path' => null,
            'air_date' => '2009-02-17',
            'episodes' => [[
                'id' => 62085,
                'season_number' => 0,
                'episode_number' => 1,
                'name' => 'Good Cop / Bad Cop',
                'overview' => 'A special.',
                'air_date' => '2009-02-17',
                'runtime' => 5,
            ]],
        ]),
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'original_name' => 'Breaking Bad',
            'first_air_date' => '2008-01-20',
            'overview' => 'A chemistry teacher changes course.',
            'poster_path' => '/poster.jpg',
            'original_language' => 'en',
            'number_of_episodes' => 62,
            'seasons' => [[
                'id' => 3627,
                'season_number' => 0,
                'name' => 'Specials',
                'air_date' => '2009-02-17',
                'episode_count' => 1,
                'overview' => '',
                'poster_path' => null,
            ]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.confirm'), [
            'tmdb_id' => 1396,
            'category' => $category,
            'season_numbers' => [0],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.category', $category)
        ->assertJsonPath('data.available_seasons.0.season_number', 0)
        ->assertJsonPath('data.available_seasons.0.episode_count', 1)
        ->assertJsonPath('data.available_seasons.0.hydrated', true)
        ->assertJsonPath('data.seasons.0.name', 'Specials')
        ->assertJsonPath('data.seasons.0.episodes.0.identity', 'S00E01')
        ->assertJsonPath('data.seasons.0.episodes.0.can_replace_current_primary', true);

    $series = Series::query()->sole();

    expect($series->category)->toBe(SeriesCategory::from($category))
        ->and($series->external_ids)->toBe(['imdb_id' => 'tt0903747', 'tvdb_id' => '81189'])
        ->and($series->seasons()->sole()->episodes()->sole()->tmdb_id)->toBe(62085);
})->with(['tv', 'anime']);

it('confirms available season summaries without hydrating an explicit empty season list', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'id' => 1396, 'imdb_id' => 'tt0903747', 'tvdb_id' => 81189,
        ]),
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            ...seriesSuggestionPayload(),
            'number_of_episodes' => 7,
            'seasons' => [[
                'id' => 3572,
                'season_number' => 1,
                'name' => 'Season 1',
                'air_date' => '2008-01-20',
                'episode_count' => 7,
                'overview' => '',
                'poster_path' => null,
            ]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.confirm'), [
            'tmdb_id' => 1396,
            'category' => 'tv',
            'season_numbers' => [],
        ])
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.available_seasons')
        ->assertJsonPath('data.available_seasons.0.name', 'Season 1')
        ->assertJsonPath('data.available_seasons.0.episode_count', 7)
        ->assertJsonPath('data.available_seasons.0.hydrated', false)
        ->assertJsonCount(0, 'data.seasons');

    expect(Series::query()->sole()->seasons()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/season/'));
});

it('hydrates all seasons only when the internal season argument is omitted', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'id' => 1396, 'imdb_id' => 'tt0903747', 'tvdb_id' => 81189,
        ]),
        'api.themoviedb.org/3/tv/1396/season/1*' => Http::response([
            'id' => 3572,
            'season_number' => 1,
            'name' => 'Season 1',
            'overview' => '',
            'poster_path' => null,
            'air_date' => '2008-01-20',
            'episodes' => [[
                'id' => 62001,
                'season_number' => 1,
                'episode_number' => 1,
                'name' => 'Pilot',
                'overview' => '',
                'air_date' => '2008-01-20',
                'runtime' => 58,
            ]],
        ]),
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            ...seriesSuggestionPayload(),
            'number_of_episodes' => 1,
            'seasons' => [[
                'id' => 3572,
                'season_number' => 1,
                'name' => 'Season 1',
                'air_date' => '2008-01-20',
                'episode_count' => 1,
                'overview' => '',
                'poster_path' => null,
            ]],
        ]),
    ]);

    $series = app(CreateOrUpdateSeries::class)->execute(1396, SeriesCategory::Tv);

    expect($series->seasons)->toHaveCount(1)
        ->and($series->seasons->firstOrFail()->episodes)->toHaveCount(1);
});

it('lazily hydrates Specials through the authenticated throttled route', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'id' => 1396, 'imdb_id' => 'tt0903747', 'tvdb_id' => 81189,
        ]),
        'api.themoviedb.org/3/tv/1396/season/0*' => Http::response([
            'id' => 3627,
            'season_number' => 0,
            'name' => 'Specials',
            'overview' => '',
            'poster_path' => null,
            'air_date' => null,
            'episodes' => [[
                'id' => 62085,
                'season_number' => 0,
                'episode_number' => 1,
                'name' => 'Special',
                'overview' => '',
                'air_date' => null,
                'runtime' => 5,
            ]],
        ]),
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            ...seriesSuggestionPayload(),
            'number_of_episodes' => 1,
            'seasons' => [[
                'id' => 3627,
                'season_number' => 0,
                'name' => 'Specials',
                'air_date' => null,
                'episode_count' => 1,
                'overview' => '',
                'poster_path' => null,
            ]],
        ]),
    ]);
    $user = User::factory()->create();
    $series = app(CreateOrUpdateSeries::class)->execute(1396, SeriesCategory::Tv, []);

    $this->postJson(route('series.seasons.hydrate', [$series, 0]))
        ->assertUnauthorized();

    $this->actingAs($user)
        ->postJson(route('series.seasons.hydrate', [$series, 0]))
        ->assertSuccessful()
        ->assertJsonPath('data.available_seasons.0.hydrated', true)
        ->assertJsonPath('data.seasons.0.episodes.0.identity', 'S00E01');
});

it('exposes exact current-primary replacement permission for owners and administrators', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'id' => 1396, 'imdb_id' => null, 'tvdb_id' => null,
        ]),
        'api.themoviedb.org/3/tv/1396*' => Http::response([
            ...seriesSuggestionPayload(),
            'number_of_episodes' => 1,
            'seasons' => [[
                'id' => 3572,
                'season_number' => 1,
                'name' => 'Season 1',
                'air_date' => null,
                'episode_count' => 1,
                'overview' => '',
                'poster_path' => null,
            ]],
        ]),
    ]);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $series = Series::factory()->create(['tmdb_id' => 1396]);
    $season = SeriesSeason::factory()->for($series)->create(['season_number' => 1]);
    $episode = SeriesEpisode::factory()->for($season, 'season')->create(['episode_number' => 1]);
    $upload = Upload::factory()->for($owner)->forSeriesEpisode($episode)->create();
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();
    $episode->update(['current_media_file_id' => $mediaFile->getKey()]);
    $payload = ['tmdb_id' => 1396, 'category' => 'tv', 'season_numbers' => []];

    $this->actingAs($owner)->postJson(route('series.confirm'), $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.seasons.0.episodes.0.can_replace_current_primary', true);
    $this->actingAs($other)->postJson(route('series.confirm'), $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.seasons.0.episodes.0.can_replace_current_primary', false);
    $this->actingAs($administrator)->postJson(route('series.confirm'), $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.seasons.0.episodes.0.can_replace_current_primary', true);
});
