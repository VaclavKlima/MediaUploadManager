<?php

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Enums\UploadStatus;
use App\Jobs\CleanupResolvedLibraryFindingFolder;
use App\Jobs\ImportLibraryFinding;
use App\Jobs\ScanMediaLibrary;
use App\Jobs\ScanMovieLibrary;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function configureLibraryScanDisk(
    string $root,
    string $seriesRoot,
    ?SeriesCategory $seriesDefaultCategory = null,
): void {
    config()->set('media', [
        'disks' => [[
            'id' => 'movies',
            'label' => 'Media',
            'movies_path' => $root,
            'series_path' => $seriesRoot,
            'series_default_category' => $seriesDefaultCategory?->value,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('services.tmdb', [
        'token' => 'test',
        'language' => 'en-US',
        'base_url' => 'https://api.themoviedb.org/3',
        'cache_ttl' => 60,
        'connect_timeout' => 1,
        'request_timeout' => 1,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    config()->set('inertia.ssr.enabled', false);
}

function libraryScanDetails(int $id, string $imdb = 'tt0133093'): array
{
    return [
        'id' => $id,
        'imdb_id' => $imdb,
        'title' => 'The Matrix',
        'original_title' => 'The Matrix',
        'release_date' => '1999-03-30',
        'overview' => 'A simulated reality.',
        'poster_path' => '/matrix.jpg',
        'original_language' => 'en',
        'runtime' => 136,
        'status' => 'Released',
        'tagline' => null,
        'vote_average' => 8.2,
        'vote_count' => 100,
        'genres' => [],
    ];
}

function libraryScanSeriesDetails(int $id = 1396): array
{
    return [
        'id' => $id,
        'name' => 'Breaking Bad',
        'original_name' => 'Breaking Bad',
        'first_air_date' => '2008-01-20',
        'overview' => 'A chemistry teacher changes careers.',
        'poster_path' => '/breaking-bad.jpg',
        'original_language' => 'en',
        'number_of_episodes' => 62,
        'seasons' => [
            ['id' => 6000, 'season_number' => 0, 'name' => 'Specials', 'air_date' => '2009-02-17', 'episode_count' => 1],
            ['id' => 6001, 'season_number' => 1, 'name' => 'Season 1', 'air_date' => '2008-01-20', 'episode_count' => 7],
        ],
    ];
}

function libraryScanSeason(int $seasonNumber, int $episodeNumber): array
{
    return [
        'id' => 6000 + $seasonNumber,
        'season_number' => $seasonNumber,
        'name' => $seasonNumber === 0 ? 'Specials' : 'Season '.$seasonNumber,
        'overview' => null,
        'poster_path' => null,
        'air_date' => $seasonNumber === 0 ? '2009-02-17' : '2008-01-20',
        'episodes' => [[
            'id' => 7000 + ($seasonNumber * 100) + $episodeNumber,
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
            'name' => $seasonNumber === 0 ? 'Special One' : 'Cat\'s in the Bag...',
            'overview' => null,
            'air_date' => '2008-01-27',
            'runtime' => 48,
        ]],
    ];
}

function fakeLibraryScanSeriesTmdb(): void
{
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/external_ids')) {
            return Http::response(['imdb_id' => 'tt0903747', 'tvdb_id' => 81189]);
        }

        if (str_contains($request->url(), '/season/0')) {
            return Http::response(libraryScanSeason(0, 1));
        }

        if (str_contains($request->url(), '/season/1')) {
            return Http::response(libraryScanSeason(1, 2));
        }

        return Http::response(libraryScanSeriesDetails());
    });
}

function libraryIdentityFinding(string $root, User $administrator, string $filename = 'Amélie source.mkv'): LibraryFinding
{
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $relativePath = 'Unsorted/'.$filename;
    $path = $root.'/'.$relativePath;
    (new Filesystem)->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, 'movie-bytes');
    $metadata = lstat($path);

    return LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => $relativePath,
        'source_folder' => 'Unsorted',
        'source_filename' => $filename,
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'needs_identification',
    ]);
}

beforeEach(function () {
    $this->scanFilesystem = new Filesystem;
    $this->scanRoot = storage_path('framework/testing/library-scan-'.bin2hex(random_bytes(6)));
    $this->seriesScanRoot = $this->scanRoot.'-series';
    $this->scanFilesystem->makeDirectory($this->scanRoot.'/.media-upload-manager/incoming', 0750, true);
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot, 0750, true);
    file_put_contents($this->seriesScanRoot.'/must-not-be-scanned.mkv', 'series bytes');
    file_put_contents($this->scanRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
    configureLibraryScanDisk($this->scanRoot, $this->seriesScanRoot);
    Cache::clear();
    Http::preventStrayRequests();
});

afterEach(function () {
    $this->scanFilesystem->deleteDirectory($this->scanRoot);
    $this->scanFilesystem->deleteDirectory($this->seriesScanRoot);
});

it('restricts the scan page and scan action to administrators', function () {
    $user = User::factory()->create();

    $this->get(route('library_scans.index'))->assertRedirect(route('login'));
    $this->actingAs($user)->get(route('library_scans.index'))->assertForbidden();
    $this->actingAs($user)->post(route('library_scans.store'))->assertForbidden();

    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $this->actingAs($administrator)->post(route('library_scans.store'))->assertRedirect(route('library_scans.index'));
    Queue::assertPushed(ScanMediaLibrary::class);
});

it('restricts identity preview and import actions to administrators', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = libraryIdentityFinding($this->scanRoot, $administrator);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 194,
    ]))->assertForbidden();
    $this->actingAs($user)->post(route('library_findings.identify_import', $finding), [
        'tmdb_id' => 194,
        'destination_relative_path' => 'anything.mkv',
    ])->assertForbidden();
    $this->actingAs($user)->post(route('library_findings.queue_import', $finding))->assertForbidden();
});

it('previews an exact Unicode canonical identity without mutating domain records and queues it once', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = libraryIdentityFinding($this->scanRoot, $administrator);
    Http::fake([
        '*/movie/194*' => Http::response([
            ...libraryScanDetails(194, 'tt0211915'),
            'title' => 'Amélie',
            'original_title' => "Le Fabuleux Destin d'Amélie Poulain",
            'release_date' => '2001-04-25',
        ]),
    ]);
    $findingBefore = $finding->fresh()->attributesToArray();

    $response = $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 194,
    ]))->assertSuccessful()
        ->assertJsonPath('data.source.relative_path', 'Unsorted/Amélie source.mkv')
        ->assertJsonPath('data.destination.relative_path', 'Amélie (2001) [tmdbid-194]/Amélie (2001) [tmdbid-194].mkv')
        ->assertJsonPath('data.movie.title', 'Amélie')
        ->assertJsonPath('data.can_import', true)
        ->assertJsonPath('data.blocker', null);

    expect($finding->fresh()->attributesToArray())->toBe($findingBefore)
        ->and(MediaItem::query()->count())->toBe(0)
        ->and(MediaFile::query()->count())->toBe(0);

    $destination = $response->json('data.destination.relative_path');
    $this->actingAs($administrator)->post(route('library_findings.identify_import', $finding), [
        'tmdb_id' => 194,
        'destination_relative_path' => $destination,
    ])->assertRedirect();
    $this->actingAs($administrator)->post(route('library_findings.identify_import', $finding), [
        'tmdb_id' => 194,
        'destination_relative_path' => $destination,
    ])->assertSessionHasErrors('tmdb_id');

    Queue::assertPushedTimes(ImportLibraryFinding::class, 1);
    $finding = $finding->fresh();
    expect($finding->status)->toBe('import_queued')
        ->and($finding->identity_snapshot['title'])->toBe('Amélie')
        ->and($finding->destination_relative_path)->toBe($destination);
});

it('rejects a stale identity destination without persisting or dispatching', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = libraryIdentityFinding($this->scanRoot, $administrator);
    Http::fake(['*/movie/194*' => Http::response([
        ...libraryScanDetails(194, 'tt0211915'),
        'title' => 'Amélie',
        'release_date' => '2001-04-25',
    ])]);

    $this->actingAs($administrator)->post(route('library_findings.identify_import', $finding), [
        'tmdb_id' => 194,
        'destination_relative_path' => 'stale/destination.mkv',
    ])->assertSessionHasErrors('tmdb_id');

    Queue::assertNothingPushed();
    expect($finding->fresh()->status)->toBe('needs_identification')
        ->and($finding->tmdb_id)->toBeNull();
});

it('persists duplicate and database identity conflicts without dispatching imports', function (string $conflict): void {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = libraryIdentityFinding($this->scanRoot, $administrator, $conflict.'.mkv');
    Http::fake(['*/movie/194*' => Http::response([
        ...libraryScanDetails(194, 'tt0211915'),
        'title' => 'Amélie',
        'release_date' => '2001-04-25',
    ])]);

    if ($conflict === 'duplicate') {
        LibraryFinding::factory()->create([
            'library_scan_id' => $finding->library_scan_id,
            'tmdb_id' => 194,
            'status' => 'ready',
        ]);
    } else {
        $movie = MediaItem::factory()->create(['tmdb_id' => 194]);
        Upload::factory()->for($movie)->for($administrator)->create();
    }

    $preview = $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 194,
    ]))->assertSuccessful()
        ->assertJsonPath('data.can_import', false)
        ->assertJsonPath('data.blocker.code', $conflict === 'duplicate' ? 'duplicate_finding' : 'database_conflict');

    $this->actingAs($administrator)->post(route('library_findings.identify_import', $finding), [
        'tmdb_id' => 194,
        'destination_relative_path' => $preview->json('data.destination.relative_path'),
    ])->assertSessionHasErrors('tmdb_id');

    Queue::assertNothingPushed();
    $finding = $finding->fresh();
    expect($finding->status)->toBe('conflict')
        ->and($finding->tmdb_id)->toBe(194);
})->with(['duplicate', 'database']);

it('recursively discovers independent videos in deterministic order and excludes the app tree and symlinks', function () {
    $this->scanFilesystem->makeDirectory($this->scanRoot.'/shared/deep/České', 0750, true);
    file_put_contents($this->scanRoot.'/shared/deep/České/unknown.mp4', 'one');
    file_put_contents($this->scanRoot.'/shared/second.mkv', 'two');
    file_put_contents($this->scanRoot.'/.media-upload-manager/incoming/ignored.mov', 'hidden');
    symlink($this->scanRoot.'/shared/second.mkv', $this->scanRoot.'/shared/linked.avi');
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMovieLibrary($scan->id), 'handle']);

    expect($scan->refresh()->status)->toBe('completed')
        ->and($scan->discovered_count)->toBe(2)
        ->and($scan->findings()->orderBy('relative_path')->pluck('relative_path')->all())->toBe([
            'shared/deep/České/unknown.mp4',
            'shared/second.mkv',
        ])
        ->and($scan->findings()->pluck('status')->unique()->all())->toBe(['needs_identification']);
});

it('scans healthy Movie and Show roots together without mutating the Show catalog', function () {
    unlink($this->seriesScanRoot.'/must-not-be-scanned.mkv');
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    $this->scanFilesystem->makeDirectory($this->scanRoot.'/loose', 0750, true);
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/Breaking Bad', 0750, true);
    file_put_contents($this->scanRoot.'/loose/unknown.mkv', 'movie');
    file_put_contents($this->seriesScanRoot.'/Breaking Bad/Breaking Bad [tmdbid-1396] S01E02.mkv', 'episode');
    file_put_contents($this->seriesScanRoot.'/Breaking Bad/Breaking Bad [tmdbid-1396] S00E01.mkv', 'special');
    Series::factory()->create(['tmdb_id' => 1396, 'category' => SeriesCategory::Tv]);
    fakeLibraryScanSeriesTmdb();
    $scan = LibraryScan::query()->create([
        'user_id' => User::factory()->create(['is_administrator' => true])->id,
        'status' => 'queued',
    ]);

    app()->call([new ScanMediaLibrary($scan->id), 'handle']);

    $showFindings = $scan->findings()->where('root_kind', MediaRootKind::Series)->orderBy('season_number')->get();

    expect($scan->refresh()->discovered_count)->toBe(3)
        ->and($scan->disk_statuses)->toHaveCount(2)
        ->and(collect($scan->disk_statuses)->pluck('root_kind')->sort()->values()->all())->toBe(['movies', 'series'])
        ->and($scan->findings()->where('root_kind', MediaRootKind::Movies)->sole()->status)->toBe('needs_identification')
        ->and($showFindings)->toHaveCount(2)
        ->and($showFindings->pluck('status')->unique()->all())->toBe(['ready'])
        ->and($showFindings->pluck('season_number')->all())->toBe([0, 1])
        ->and($showFindings->pluck('series_category')->unique()->sole())->toBe(SeriesCategory::Tv)
        ->and(SeriesSeason::query()->count())->toBe(0)
        ->and(SeriesEpisode::query()->count())->toBe(0);
});

it('keeps scan paths unique per root kind', function () {
    $scan = LibraryScan::factory()->create();

    $movie = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Movies,
        'disk_id' => 'movies',
        'relative_path' => 'shared/title.mkv',
    ]);
    $show = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'disk_id' => 'movies',
        'relative_path' => 'shared/title.mkv',
    ]);

    expect($movie->path_key)->not->toBe($show->path_key)
        ->and($scan->findings()->count())->toBe(2);
});

it('enforces finding subject kinds and retains Show scan history after episode deletion', function () {
    $scan = LibraryScan::factory()->create();
    $movie = MediaItem::factory()->create();
    $series = Series::factory()->create();
    $season = SeriesSeason::factory()->for($series)->create();
    $episode = SeriesEpisode::factory()->for($season, 'season')->create();

    expect(fn () => LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'media_item_id' => $movie->id,
    ]))->toThrow(DomainException::class, 'root kind')
        ->and(fn () => LibraryFinding::factory()->create([
            'library_scan_id' => $scan->id,
            'root_kind' => MediaRootKind::Movies,
            'media_item_id' => $movie->id,
            'series_episode_id' => $episode->id,
        ]))->toThrow(DomainException::class, 'both');

    $finding = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'series_episode_id' => $episode->id,
    ]);
    $episode->delete();

    expect($finding->refresh()->series_episode_id)->toBeNull()
        ->and($finding->root_kind)->toBe(MediaRootKind::Series);
});

it('previews a manually corrected Show episode without creating catalog records', function () {
    Queue::fake();
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    $source = $this->seriesScanRoot.'/loose/wrong-token.mkv';
    $this->scanFilesystem->makeDirectory(dirname($source), 0750, true);
    file_put_contents($source, 'show-episode');
    $metadata = lstat($source);
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::factory()->for($administrator)->create();
    $finding = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'disk_id' => 'movies',
        'relative_path' => 'loose/wrong-token.mkv',
        'source_folder' => 'loose',
        'source_filename' => 'wrong-token.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'status' => 'needs_identification',
    ]);
    fakeLibraryScanSeriesTmdb();

    $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 1396,
        'season_number' => 1,
        'episode_number' => 2,
    ]))->assertUnprocessable();

    $preview = $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 1396,
        'category' => 'anime',
        'season_number' => 1,
        'episode_number' => 2,
    ]))->assertSuccessful()
        ->assertJsonPath('data.media_type', 'show')
        ->assertJsonPath('data.show.category', 'anime')
        ->assertJsonPath('data.show.season_number', 1)
        ->assertJsonPath('data.show.episode_number', 2)
        ->assertJsonPath('data.can_import', true);

    expect(Series::query()->count())->toBe(0)
        ->and(SeriesSeason::query()->count())->toBe(0)
        ->and(SeriesEpisode::query()->count())->toBe(0);

    $this->actingAs($administrator)->post(route('library_findings.identify_import', $finding), [
        'tmdb_id' => 1396,
        'category' => 'anime',
        'season_number' => 1,
        'episode_number' => 2,
        'destination_relative_path' => $preview->json('data.destination.relative_path'),
    ])->assertRedirect();

    Queue::assertPushed(ImportLibraryFinding::class);
    expect($finding->refresh()->series_category)->toBe(SeriesCategory::Anime)
        ->and($finding->season_number)->toBe(1)
        ->and($finding->episode_number)->toBe(2)
        ->and($finding->status)->toBe('import_queued');
});

it('keeps an existing Show category authoritative before its episode is catalogued', function () {
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    $source = $this->seriesScanRoot.'/Death Note Complete ENG SUB 1080p/Season 1/episode.mkv';
    $this->scanFilesystem->makeDirectory(dirname($source), 0750, true);
    file_put_contents($source, 'show-episode');
    $metadata = lstat($source);
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::factory()->for($administrator)->create();
    Series::factory()->create(['tmdb_id' => 1396, 'category' => SeriesCategory::Anime]);
    $finding = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'disk_id' => 'movies',
        'relative_path' => 'Death Note Complete ENG SUB 1080p/Season 1/episode.mkv',
        'source_folder' => 'Death Note Complete ENG SUB 1080p/Season 1',
        'source_filename' => 'episode.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'status' => 'needs_identification',
        'tmdb_id' => 1396,
    ]);
    fakeLibraryScanSeriesTmdb();

    $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 1396,
        'category' => 'tv',
        'season_number' => 1,
        'episode_number' => 2,
    ]))->assertUnprocessable();

    $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $finding,
        'tmdb_id' => 1396,
        'season_number' => 1,
        'episode_number' => 2,
    ]))->assertSuccessful()
        ->assertJsonPath('data.show.category', 'anime');

    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.media_type', 'show')
            ->where('tasks.0.relative_path', 'Death Note Complete ENG SUB 1080p/Season 1/episode.mkv')
            ->where('tasks.0.source_folder', 'Death Note Complete ENG SUB 1080p/Season 1')
            ->where('tasks.0.show.search_query', 'Death Note Complete ENG SUB 1080p')
            ->where('tasks.0.show.category', 'anime')
            ->where('tasks.0.show.category_required', false));
});

it('marks duplicate unresolved files for one Show episode as conflicts', function () {
    unlink($this->seriesScanRoot.'/must-not-be-scanned.mkv');
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/duplicates', 0750, true);
    file_put_contents($this->seriesScanRoot.'/duplicates/first [tmdbid-1396] S01E02.mkv', 'first');
    file_put_contents($this->seriesScanRoot.'/duplicates/second [tmdbid-1396] S01E02.mp4', 'second');
    fakeLibraryScanSeriesTmdb();
    $scan = LibraryScan::factory()->create(['status' => 'queued']);

    app()->call([new ScanMediaLibrary($scan->id), 'handle']);

    expect($scan->findings()->where('root_kind', MediaRootKind::Series)->pluck('status')->all())
        ->toBe(['conflict', 'conflict'])
        ->and($scan->findings()->where('root_kind', MediaRootKind::Series)->pluck('error_detail')->unique()->sole())
        ->toContain('same Show episode');
});

it('keeps new Shows manual when unset and preserves an existing category when opted in', function () {
    Queue::fake();
    unlink($this->seriesScanRoot.'/must-not-be-scanned.mkv');
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    $source = $this->seriesScanRoot.'/Incoming/Breaking Bad [tmdbid-1396] S01E02.mkv';
    $this->scanFilesystem->makeDirectory(dirname($source), 0750, true);
    file_put_contents($source, 'episode');
    fakeLibraryScanSeriesTmdb();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $manualScan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMediaLibrary($manualScan->id), 'handle']);

    expect($manualScan->findings()->where('root_kind', MediaRootKind::Series)->sole()->status)
        ->toBe('needs_identification');
    Queue::assertNotPushed(ImportLibraryFinding::class);

    Series::factory()->create(['tmdb_id' => 1396, 'category' => SeriesCategory::Anime]);
    configureLibraryScanDisk($this->scanRoot, $this->seriesScanRoot, SeriesCategory::Tv);
    $automaticScan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMediaLibrary($automaticScan->id), 'handle']);

    $finding = $automaticScan->findings()->where('root_kind', MediaRootKind::Series)->sole();
    expect($finding->status)->toBe('import_queued')
        ->and($finding->series_category)->toBe(SeriesCategory::Anime);
    Queue::assertPushed(ImportLibraryFinding::class, 1);
});

it('keeps unsafe or unconfirmed Show identities in review on an automatic Series root', function () {
    Queue::fake();
    unlink($this->seriesScanRoot.'/must-not-be-scanned.mkv');
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    configureLibraryScanDisk($this->scanRoot, $this->seriesScanRoot, SeriesCategory::Tv);
    $paths = [
        'review/Missing Show S01E02.mkv',
        'review/Contradictory [tmdbid-1396] [tmdbid-246] S01E02.mkv',
        'review/Multipart [tmdbid-1396] S01E02 Part 2.mkv',
        'review/Invalid [tmdbid-1396] S01E00.mkv',
        'review/Unknown episode [tmdbid-1396] S01E03.mkv',
        'review/TMDB failure [tmdbid-999] S01E02.mkv',
    ];

    foreach ($paths as $path) {
        $absolutePath = $this->seriesScanRoot.'/'.$path;

        if (! is_dir(dirname($absolutePath))) {
            $this->scanFilesystem->makeDirectory(dirname($absolutePath), 0750, true);
        }

        file_put_contents($absolutePath, $path);
    }

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/tv/999')) {
            return Http::response([], 404);
        }

        if (str_contains($request->url(), '/external_ids')) {
            return Http::response(['imdb_id' => 'tt0903747', 'tvdb_id' => 81189]);
        }

        if (str_contains($request->url(), '/season/1')) {
            return Http::response(libraryScanSeason(1, 2));
        }

        return Http::response(libraryScanSeriesDetails());
    });
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMediaLibrary($scan->id), 'handle']);

    $findings = $scan->findings()->where('root_kind', MediaRootKind::Series)->get()->keyBy('source_filename');

    expect($findings)->toHaveCount(6)
        ->and($findings['Missing Show S01E02.mkv']->status)->toBe('needs_identification')
        ->and($findings['Contradictory [tmdbid-1396] [tmdbid-246] S01E02.mkv']->status)->toBe('conflict')
        ->and($findings['Multipart [tmdbid-1396] S01E02 Part 2.mkv']->status)->toBe('needs_identification')
        ->and($findings['Invalid [tmdbid-1396] S01E00.mkv']->status)->toBe('needs_identification')
        ->and($findings['Unknown episode [tmdbid-1396] S01E03.mkv']->status)->toBe('needs_identification')
        ->and($findings['TMDB failure [tmdbid-999] S01E02.mkv']->status)->toBe('needs_identification');
    Queue::assertNotPushed(ImportLibraryFinding::class);
});

it('does not automatically queue an occupied canonical Show destination', function () {
    Queue::fake();
    unlink($this->seriesScanRoot.'/must-not-be-scanned.mkv');
    $this->scanFilesystem->makeDirectory($this->seriesScanRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesScanRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    configureLibraryScanDisk($this->scanRoot, $this->seriesScanRoot, SeriesCategory::Tv);
    $source = $this->seriesScanRoot.'/Incoming/Breaking Bad [tmdbid-1396] S01E02.mkv';
    $destination = $this->seriesScanRoot."/Breaking Bad (2008) [tmdbid-1396]/Season 01/Breaking Bad S01E02 - Cat's in the Bag/Breaking Bad S01E02 - Cat's in the Bag.mkv";
    $this->scanFilesystem->makeDirectory(dirname($source), 0750, true);
    $this->scanFilesystem->makeDirectory($destination, 0750, true);
    file_put_contents($source, 'episode');
    fakeLibraryScanSeriesTmdb();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMediaLibrary($scan->id), 'handle']);

    $finding = $scan->findings()->where('root_kind', MediaRootKind::Series)->sole();
    expect($finding->status)->toBe('conflict')
        ->and($finding->error_detail)->toContain('destination is already occupied');
    Queue::assertNotPushed(ImportLibraryFinding::class);
});

it('resolves agreeing exact tags and flags mismatched tags without creating movie records', function () {
    $this->scanFilesystem->makeDirectory($this->scanRoot.'/incoming', 0750, true);
    file_put_contents($this->scanRoot.'/incoming/Matrix [tmdbid-603] [imdbid-tt0133093].mkv', 'one');
    file_put_contents($this->scanRoot.'/incoming/Wrong [tmdbid-604] [imdbid-tt9999999].mp4', 'two');
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/find/tt9999999')) {
            return Http::response(['movie_results' => [['id' => 999]]]);
        }

        if (str_contains($request->url(), '/movie/999')) {
            return Http::response(libraryScanDetails(999, 'tt9999999'));
        }

        if (str_contains($request->url(), '/find/')) {
            return Http::response(['movie_results' => [['id' => 603]]]);
        }

        return Http::response(libraryScanDetails(str_contains($request->url(), '/movie/604') ? 604 : 603));
    });
    $scan = LibraryScan::query()->create([
        'user_id' => User::factory()->create(['is_administrator' => true])->id,
        'status' => 'queued',
    ]);

    app()->call([new ScanMovieLibrary($scan->id), 'handle']);

    expect($scan->findings()->where('source_filename', 'like', 'Matrix%')->value('status'))->toBe('ready')
        ->and($scan->findings()->where('source_filename', 'like', 'Wrong%')->value('status'))->toBe('conflict')
        ->and(MediaItem::query()->count())->toBe(0);
});

it('marks tracked primaries missing only on healthy disks and resolves the finding when bytes return', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $movie = MediaItem::factory()->create();
    $upload = Upload::factory()->for($movie)->for($administrator)->create(['declared_size' => 10]);
    Upload::query()->whereKey($upload)->update(['status' => UploadStatus::Completed->value]);
    $file = MediaFile::factory()->forUpload($upload->refresh())->create(['disk_id' => 'movies']);
    $movie->update(['current_media_file_id' => $file->id]);
    $firstScan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);
    app()->call([new ScanMovieLibrary($firstScan->id), 'handle']);
    $missing = $firstScan->findings()->sole();

    expect($missing->status)->toBe('missing');

    $path = $this->scanRoot.'/'.$file->relative_path;
    $this->scanFilesystem->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, str_repeat('x', $file->size_bytes));
    $secondScan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);
    app()->call([new ScanMovieLibrary($secondScan->id), 'handle']);

    expect($missing->refresh()->resolution)->toBe('restored')
        ->and($secondScan->refresh()->missing_count)->toBe(0);

    unlink($this->scanRoot.'/.media-upload-manager/disk.json');
    unlink($path);
    $thirdScan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);
    app()->call([new ScanMovieLibrary($thirdScan->id), 'handle']);

    expect($thirdScan->refresh()->missing_count)->toBe(0)
        ->and(collect($thirdScan->disk_statuses)->first()['health'])->toBe('unhealthy');
});

it('renders a prioritized task queue with processing counts compact history and maintenance state', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $historicalScan = LibraryScan::factory()->for($administrator)->create();

    foreach (range(1, 21) as $index) {
        LibraryFinding::factory()->create([
            'library_scan_id' => $historicalScan->id,
            'source_filename' => "completed-{$index}.mkv",
            'relative_path' => "history/completed-{$index}.mkv",
            'status' => 'resolved',
            'resolution' => 'imported',
            'resolved_at' => now()->subMinutes($index),
        ]);
    }

    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $createFinding = fn (array $attributes): LibraryFinding => LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        ...$attributes,
    ]);
    $createFinding(['status' => 'ready', 'source_filename' => 'ready.mkv', 'relative_path' => 'ready.mkv']);
    $createFinding(['status' => 'needs_identification', 'source_filename' => 'identify.mkv', 'relative_path' => 'identify.mkv']);
    $createFinding([
        'status' => 'failed',
        'source_filename' => 'retry-import.mkv',
        'relative_path' => 'retry-import.mkv',
        'operation_claim' => ['type' => 'import'],
    ]);
    $createFinding([
        'status' => 'failed',
        'source_filename' => 'retry-delete.mkv',
        'relative_path' => 'retry-delete.mkv',
        'operation_claim' => ['type' => 'delete'],
    ]);
    $createFinding([
        'kind' => 'missing',
        'status' => 'missing',
        'source_filename' => 'missing.mkv',
        'relative_path' => 'missing.mkv',
    ]);
    $createFinding(['status' => 'import_queued', 'source_filename' => 'processing.mkv', 'relative_path' => 'processing.mkv']);
    $createFinding([
        'status' => 'resolved',
        'resolution' => 'deleted',
        'resolved_at' => now(),
        'source_filename' => 'done.mkv',
        'relative_path' => 'done.mkv',
    ]);
    FolderCleanup::factory()->for($administrator)->create([
        'status' => 'failed',
        'error_detail' => 'Residue changed.',
    ]);

    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('library-scans/Index')
            ->where('tasks.0.task_type', 'identify')
            ->where('tasks.1.task_type', 'retry_import')
            ->where('tasks.2.task_type', 'retry_delete')
            ->where('tasks.3.task_type', 'import')
            ->where('tasks.4.task_type', 'missing')
            ->where('remaining_count', 5)
            ->where('processing_count', 1)
            ->where('progress.completed', 1)
            ->where('progress.total', 7)
            ->has('history', 20)
            ->where('history.0.name', 'done.mkv')
            ->where('maintenance_warning.count', 1)
            ->where('maintenance_warning.message', 'Residue changed.'));
});

it('reconciles and advances the focused queue after every successful action', function () {
    $page = file_get_contents(resource_path('js/pages/library-scans/Index.vue'));

    expect($page)
        ->toContain('const selected = visibleTasks.value.find(')
        ->toContain('selected ??')
        ->toContain('onSuccess: reconcileQueue')
        ->toContain('stopQueuePolling();')
        ->toContain('router.reload({')
        ->toContain('onFinish: startQueuePolling')
        ->toContain('props.processing_count, props.progress.completed');
});

it('matches Show identification search to the compact Movie selection flow', function () {
    $page = file_get_contents(resource_path('js/pages/library-scans/Index.vue'));

    expect($page)
        ->toContain('task.show.name ?? task.show.search_query')
        ->toContain('Parent folders:')
        ->toContain('{{ identifyTarget.source_folder }}')
        ->toContain('const showOverviewCharacterLimit = 80')
        ->toContain('limitShowOverview(')
        ->toContain('result.overview')
        ->toContain('v-for="index in 6"')
        ->toContain('aria-label="Loading Show results"')
        ->toContain('sm:grid-cols-2 xl:grid-cols-3')
        ->toContain('class="flex h-28 w-20 shrink-0')
        ->toContain('<h3 class="font-semibold">Matches</h3>')
        ->toContain('{{ showResults.length }} results')
        ->toContain('v-if="result.poster_url"')
        ->toContain('v-if="selectedShow.poster_url"')
        ->toContain(':alt="`${result.name} poster`"')
        ->toContain(':alt="`${selectedShow.name} poster`"')
        ->toContain('result.original_name !==')
        ->toContain('line-clamp-2')
        ->toContain('const highlightedShow = ref<SeriesSearchResult | null>(null)')
        ->toContain('@click="highlightedShow = result"')
        ->toContain(':aria-pressed="')
        ->toContain("'opacity-40 blur-[1px]'")
        ->toContain('class="absolute inset-0 z-10 m-auto w-fit shadow-lg"')
        ->toContain('@click="selectShow(result)"')
        ->toContain("'Show details could not be loaded.'")
        ->toContain('border border-destructive/30 bg-destructive/10')
        ->toContain('role="status"')
        ->toContain('border border-dashed bg-muted/20')
        ->toContain('No Shows found')
        ->toContain('enter its numeric TMDB ID')
        ->toContain('@click="previewSelectedIdentity(true)"')
        ->toContain('submitIdentityAndImport(target, response.data)')
        ->toContain("? 'Importing…'")
        ->toContain(": 'Import'")
        ->not->toContain('Preview import')
        ->not->toContain('SearchX');
});

it('does not expose a manual cleanup action for resolved findings', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => 'old/movie.mkv',
        'source_folder' => 'old',
        'source_filename' => 'movie.mkv',
        'size_bytes' => 5,
        'device_id' => 1,
        'inode_id' => 2,
        'kind' => 'discovered',
        'status' => 'resolved',
        'resolution' => 'deleted',
        'resolved_at' => now(),
    ]);
    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 0)
            ->missing('history.0.can_cleanup'));
});

it('queues automatic folder cleanup for historical resolved findings after a scan', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $historicalScan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $historicalScan->id,
        'disk_id' => 'movies',
        'relative_path' => 'old/movie.mkv',
        'source_folder' => 'old',
        'source_filename' => 'movie.mkv',
        'size_bytes' => 5,
        'device_id' => 1,
        'inode_id' => 2,
        'kind' => 'discovered',
        'status' => 'resolved',
        'resolution' => 'deleted',
        'resolved_at' => now(),
    ]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMovieLibrary($scan->id), 'handle']);

    Queue::assertPushed(
        CleanupResolvedLibraryFindingFolder::class,
        fn (CleanupResolvedLibraryFindingFolder $job): bool => $job->findingId === $finding->id
            && $job->actorId === $administrator->id,
    );
});
