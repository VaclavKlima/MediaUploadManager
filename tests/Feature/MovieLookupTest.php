<?php

use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function tmdbSearchPayload(array $results = []): array
{
    return ['page' => 1, 'results' => $results, 'total_pages' => 1, 'total_results' => count($results)];
}

function tmdbSummaryPayload(int $id = 603, string $title = 'The Matrix', string $releaseDate = '1999-03-30'): array
{
    return [
        'id' => $id,
        'title' => $title,
        'original_title' => $title,
        'release_date' => $releaseDate,
        'overview' => 'A simulated reality.',
        'poster_path' => '/matrix.jpg',
        'original_language' => 'en',
    ];
}

function tmdbDetailsPayload(int $id = 603, string $title = 'The Matrix'): array
{
    return [
        ...tmdbSummaryPayload($id, $title),
        'imdb_id' => 'tt0133093',
        'runtime' => 136,
        'status' => 'Released',
        'tagline' => 'Welcome to the Real World.',
        'vote_average' => 8.2,
        'vote_count' => 26000,
        'genres' => [
            ['id' => 28, 'name' => 'Action'],
            ['id' => 878, 'name' => 'Science Fiction'],
        ],
    ];
}

beforeEach(function () {
    config()->set('cache.default', 'array');
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

it('requires authentication for every movie endpoint', function (string $method, string $uri) {
    $response = $method === 'post'
        ? $this->post($uri, ['tmdb_id' => 603])
        : $this->get($uri);

    $response->assertRedirect(route('login'));
})->with([
    ['get', '/movies/search?query=Matrix'],
    ['get', '/movies/suggestions?filename=Matrix.mkv'],
    ['get', '/movies/tmdb/603'],
    ['get', '/movies/imdb/tt0133093'],
    ['post', '/movies/confirm'],
]);

it('searches movies and exposes only normalized fields', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(tmdbSearchPayload([
            tmdbSummaryPayload(),
        ])),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.search', ['query' => '  The Matrix  ', 'year' => 1999]))
        ->assertSuccessful()
        ->assertJsonPath('meta.source', 'text')
        ->assertJsonPath('data.0.tmdb_id', 603)
        ->assertJsonPath('data.0.release_year', 1999)
        ->assertJsonPath('data.0.poster_url', 'https://image.tmdb.org/t/p/w342/matrix.jpg')
        ->assertJsonMissingPath('data.0.popularity');

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-tmdb-token')
            && $request['query'] === 'The Matrix'
            && $request['primary_release_year'] === 1999
            && ! isset($request['year'])
            && $request['language'] === 'en-US';
    });
});

it('parses filenames and deterministically ranks suggestions', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(tmdbSearchPayload([
            tmdbSummaryPayload(1, 'Matrix Generations'),
            tmdbSummaryPayload(603, 'The Matrix'),
        ])),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', [
            'filename' => 'The.Matrix.1999.1080p.BluRay.x264-GROUP.mkv',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('meta.source', 'filename')
        ->assertJsonPath('meta.parsed.title', 'The Matrix')
        ->assertJsonPath('meta.parsed.year', 1999)
        ->assertJsonPath('data.0.tmdb_id', 603);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['query'] === 'The Matrix'
        && $request['primary_release_year'] === 1999);
});

it('retries a weak year-constrained result without the year', function () {
    Http::fake(function (Request $request) {
        if (isset($request['primary_release_year'])) {
            return Http::response(tmdbSearchPayload([
                tmdbSummaryPayload(841, 'Dune', '1984-12-14'),
            ]));
        }

        return Http::response(tmdbSearchPayload([
            tmdbSummaryPayload(438631, 'Dune', '2021-09-15'),
        ]));
    });

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', ['filename' => 'Dune.2021.mkv']))
        ->assertSuccessful()
        ->assertJsonPath('data.0.tmdb_id', 438631);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['query'] === 'Dune'
        && ! isset($request['primary_release_year']));
});

it('falls back to a title before its explicit subtitle', function () {
    Http::fake(fn (Request $request) => Http::response(tmdbSearchPayload(
        $request['query'] === 'Amélie'
            ? [tmdbSummaryPayload(194, 'Amélie', '2001-04-25')]
            : [tmdbSummaryPayload(999, 'An unrelated movie', '2002-01-01')],
    )));

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', [
            'filename' => 'Amélie: Le fabuleux destin.mkv',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('meta.parsed.title', 'Amélie: Le fabuleux destin')
        ->assertJsonPath('data.0.tmdb_id', 194);

    Http::assertSentCount(3);
});

it('falls back to an ASCII transliteration when materially different', function () {
    Http::fake(fn (Request $request) => Http::response(tmdbSearchPayload(
        $request['query'] === 'Qian toQian Xun noShen Yin shi'
            ? [tmdbSummaryPayload(129, 'Spirited Away', '2001-07-20')]
            : [],
    )));

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', ['filename' => '千と千尋の神隠し.mkv']))
        ->assertSuccessful()
        ->assertJsonPath('data.0.tmdb_id', 129);

    Http::assertSentCount(2);
});

it('limits adaptive suggestions to three TMDB searches', function () {
    Http::fake(fn (Request $request) => Http::response(tmdbSearchPayload(
        $request['query'] === 'Amélie' && ! isset($request['primary_release_year'])
            ? [tmdbSummaryPayload(194, 'Amélie', '2001-04-25')]
            : [],
    )));

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', [
            'filename' => 'Amélie: Le fabuleux destin.2001.mkv',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.tmdb_id', 194);

    Http::assertSentCount(3);
    $queries = Http::recorded()
        ->map(fn (array $recorded): string => $recorded[0]['query'])
        ->all();

    expect($queries)->toBe([
        'Amélie: Le fabuleux destin',
        'Amélie: Le fabuleux destin',
        'Amélie',
    ])->not->toContain('Amelie: Le fabuleux destin');
});

it('caches each adaptive query independently', function () {
    Http::fake(function (Request $request) {
        return isset($request['primary_release_year'])
            ? Http::response(tmdbSearchPayload([tmdbSummaryPayload(841, 'Dune', '1984-12-14')]))
            : Http::response(tmdbSearchPayload([tmdbSummaryPayload(438631, 'Dune', '2021-09-15')]));
    });
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('movies.suggestions', ['filename' => 'Dune.2021.mkv']))
        ->assertSuccessful();
    $this->actingAs($user)
        ->getJson(route('movies.suggestions', ['filename' => 'Dune.2021.mkv']))
        ->assertSuccessful();

    Http::assertSentCount(2);
});

it('returns primary candidates when an optional fallback fails', function () {
    Http::fake(function (Request $request) {
        return isset($request['primary_release_year'])
            ? Http::response(tmdbSearchPayload([tmdbSummaryPayload(841, 'Dune', '1984-12-14')]))
            : Http::response(['results' => [['id' => 'invalid']]]);
    });

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', ['filename' => 'Dune.2021.mkv']))
        ->assertSuccessful()
        ->assertJsonPath('data.0.tmdb_id', 841);

    Http::assertSentCount(2);
});

it('keeps primary suggestion failures as safe upstream errors', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(['private' => 'upstream detail'], 503),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.suggestions', ['filename' => 'Dune.2021.mkv']))
        ->assertServiceUnavailable()
        ->assertJsonPath('error', 'movie_lookup_unavailable');

    expect($response->getContent())->not->toContain('upstream detail')
        ->not->toContain('test-tmdb-token');
    Http::assertSentCount(3);
});

it('returns and caches successful empty searches', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(tmdbSearchPayload()),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('movies.search', ['query' => 'No Such Movie']))
        ->assertSuccessful()
        ->assertExactJson(['data' => [], 'meta' => ['source' => 'text']]);
    $this->actingAs($user)->getJson(route('movies.search', ['query' => 'No Such Movie']))
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('looks up complete details by TMDB ID', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/603*' => Http::response(tmdbDetailsPayload()),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.tmdb.show', ['tmdbId' => 603]))
        ->assertSuccessful()
        ->assertJsonPath('data.tmdb_id', 603)
        ->assertJsonPath('data.imdb_id', 'tt0133093')
        ->assertJsonPath('data.runtime', 136)
        ->assertJsonPath('data.genres.1.name', 'Science Fiction');
});

it('resolves IMDb IDs through find before retrieving details', function () {
    Http::fake([
        'api.themoviedb.org/3/find/tt0133093*' => Http::response([
            'movie_results' => [['id' => 603]],
        ]),
        'api.themoviedb.org/3/movie/603*' => Http::response(tmdbDetailsPayload()),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.imdb.show', ['imdbId' => 'tt0133093']))
        ->assertSuccessful()
        ->assertJsonPath('data.tmdb_id', 603);

    Http::assertSentCount(2);
});

it('maps missing movies to a safe not found error', function () {
    Http::fake([
        'api.themoviedb.org/3/find/tt9999999*' => Http::response(['movie_results' => []]),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.imdb.show', ['imdbId' => 'tt9999999']))
        ->assertNotFound()
        ->assertExactJson([
            'error' => 'movie_not_found',
            'message' => 'The requested movie could not be found.',
        ]);
});

it('retries transient server failures and then succeeds', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/603*' => Http::sequence()
            ->push(['upstream_secret' => 'never expose this'], 500)
            ->push(tmdbDetailsPayload()),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.tmdb.show', ['tmdbId' => 603]))
        ->assertSuccessful();

    Http::assertSentCount(2);
});

it('maps exhausted upstream rate limits without leaking the body or token', function () {
    Http::fake([
        'api.themoviedb.org/3/*' => Http::response([
            'status_message' => 'raw upstream secret body',
        ], 429),
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.tmdb.show', ['tmdbId' => 603]))
        ->assertServiceUnavailable()
        ->assertJsonPath('error', 'movie_lookup_rate_limited');

    expect($response->getContent())->not->toContain('raw upstream secret body')
        ->not->toContain('test-tmdb-token');
    Http::assertSentCount(3);
});

it('maps connection failures and exhausted server failures to unavailable', function (string $failure) {
    Http::fake([
        'api.themoviedb.org/3/*' => $failure === 'connection'
            ? Http::failedConnection()
            : Http::response(['private' => 'upstream detail'], 503),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.tmdb.show', ['tmdbId' => 603]))
        ->assertServiceUnavailable()
        ->assertJsonPath('error', 'movie_lookup_unavailable');
})->with(['connection', 'server']);

it('rejects malformed upstream payloads and never caches them', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::sequence()
            ->push(['results' => [['id' => '603', 'title' => ['invalid']]]])
            ->push(tmdbSearchPayload()),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('movies.search', ['query' => 'Matrix']))
        ->assertStatus(502)
        ->assertJsonPath('error', 'movie_lookup_invalid_response');
    $this->actingAs($user)
        ->getJson(route('movies.search', ['query' => 'Matrix']))
        ->assertSuccessful();

    Http::assertSentCount(2);
});

it('returns unavailable without making a request when configuration is missing', function () {
    config()->set('services.tmdb.token', null);
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.search', ['query' => 'Matrix']))
        ->assertServiceUnavailable()
        ->assertJsonPath('error', 'movie_lookup_unavailable');

    Http::assertNothingSent();
});

it('validates lookup and confirmation inputs', function (string $method, string $uri, array $data, string $field) {
    $user = User::factory()->create();
    $response = $method === 'post'
        ? $this->actingAs($user)->postJson($uri, $data)
        : $this->actingAs($user)->getJson($uri);

    $response->assertUnprocessable()->assertJsonValidationErrorFor($field);
})->with([
    ['get', '/movies/search?query=', [], 'query'],
    ['get', '/movies/search?query=Matrix&year=1700', [], 'year'],
    ['get', '/movies/suggestions?filename=', [], 'filename'],
    ['post', '/movies/confirm', ['tmdb_id' => 0], 'tmdb_id'],
]);

it('enforces the local per-user movie lookup throttle with a safe error', function () {
    $user = User::factory()->create();

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($user)
            ->getJson('/movies/search')
            ->assertUnprocessable();
    }

    $this->actingAs($user)
        ->getJson('/movies/search')
        ->assertTooManyRequests()
        ->assertJsonPath('error', 'movie_lookup_throttled');
});

it('confirms a movie and reuses its immutable metadata snapshot', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/603*' => Http::response(tmdbDetailsPayload()),
    ]);
    $user = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson(route('movies.confirm'), ['tmdb_id' => 603])
        ->assertSuccessful()
        ->assertJsonPath('reused', false)
        ->assertJsonPath('has_current_primary', false)
        ->assertJsonPath('data.title', 'The Matrix');

    $mediaItemId = $created->json('media_item_id');
    Http::fake([
        'api.themoviedb.org/3/movie/603*' => Http::response(tmdbDetailsPayload(603, 'Changed upstream title')),
    ]);

    $this->actingAs($user)
        ->postJson(route('movies.confirm'), ['tmdb_id' => 603])
        ->assertSuccessful()
        ->assertJsonPath('media_item_id', $mediaItemId)
        ->assertJsonPath('reused', true)
        ->assertJsonPath('data.title', 'The Matrix');

    expect(MediaItem::query()->where('tmdb_id', 603)->sole()->metadata_snapshot['title'])
        ->toBe('The Matrix');
    Http::assertNothingSent();
});

it('reports whether a reused movie already has a current primary', function () {
    $mediaItem = MediaItem::factory()->create(['tmdb_id' => 603]);
    $upload = Upload::factory()->for($mediaItem)->create();
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();
    $mediaItem->update(['current_media_file_id' => $mediaFile->id]);
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson(route('movies.confirm'), ['tmdb_id' => 603])
        ->assertSuccessful()
        ->assertJsonPath('reused', true)
        ->assertJsonPath('has_current_primary', true);

    Http::assertNothingSent();
});
