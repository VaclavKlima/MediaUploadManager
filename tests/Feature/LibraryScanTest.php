<?php

use App\Enums\UploadStatus;
use App\Jobs\CleanupResolvedLibraryFindingFolder;
use App\Jobs\ImportLibraryFinding;
use App\Jobs\ScanMovieLibrary;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
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

function configureLibraryScanDisk(string $root, string $seriesRoot): void
{
    config()->set('media', [
        'disks' => [[
            'id' => 'movies',
            'label' => 'Media',
            'movies_path' => $root,
            'series_path' => $seriesRoot,
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
    Queue::assertPushed(ScanMovieLibrary::class);
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
            ->component('movies/Scan')
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
    $page = file_get_contents(resource_path('js/pages/movies/Scan.vue'));

    expect($page)
        ->toContain('const selectedTask = visibleTasks.value.find(')
        ->toContain('selectedTask ??')
        ->toContain('onSuccess: reconcileQueue')
        ->toContain('stopQueuePolling();')
        ->toContain('router.reload({')
        ->toContain('onFinish: startQueuePolling')
        ->toContain('props.processing_count, props.progress.completed');
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
