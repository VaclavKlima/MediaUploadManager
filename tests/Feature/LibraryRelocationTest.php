<?php

use App\Actions\ReconcileMissingMediaFile;
use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Jobs\ImportLibraryFinding;
use App\Jobs\ScanMovieLibrary;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\LibraryImportProcessor;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function configureRelocationDisks(array $disks): void
{
    config()->set('media', [
        'disks' => collect($disks)->map(
            fn (string $path, string $id): array => [
                'id' => $id,
                'label' => ucfirst($id),
                'path' => $path,
                'reserve_gib' => '0',
            ],
        )->values()->all(),
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload.fingerprint_window_bytes', '4');
    config()->set('inertia.ssr.enabled', false);
    config()->set('services.tmdb', [
        'token' => 'test',
        'language' => 'en-US',
        'base_url' => 'https://api.themoviedb.org/3',
        'cache_ttl' => 60,
        'connect_timeout' => 1,
        'request_timeout' => 1,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

function relocationTmdbDetails(): array
{
    return [
        'id' => 603,
        'imdb_id' => 'tt0133093',
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

function createImportedRelocationPrimary(
    string $root,
    User $administrator,
    string $bytes = 'movie-bytes',
): array {
    $movie = MediaItem::factory()->create([
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'title' => 'The Matrix',
        'release_year' => 1999,
    ]);
    $relativePath = 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv';
    $path = $root.'/'.$relativePath;
    (new Filesystem)->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, $bytes);
    $metadata = lstat($path);
    $importScan = LibraryScan::factory()->for($administrator)->create(['status' => 'completed']);
    $importFinding = LibraryFinding::factory()->create([
        'library_scan_id' => $importScan->id,
        'media_item_id' => $movie->id,
        'disk_id' => 'movies',
        'relative_path' => 'incoming/original.mkv',
        'source_folder' => 'incoming',
        'source_filename' => 'original.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'status' => 'resolved',
        'operation_claim' => [
            'version' => 1,
            'type' => 'import',
            'size_bytes' => $metadata['size'],
            'device_id' => $metadata['dev'],
            'inode_id' => $metadata['ino'],
        ],
        'resolution' => 'imported',
        'resolved_at' => now(),
    ]);
    $mediaFile = MediaFile::query()->create([
        'media_item_id' => $movie->id,
        'source_upload_id' => null,
        'imported_by_user_id' => $administrator->id,
        'import_provenance' => [
            'type' => 'recursive_library_import',
            'library_scan_id' => $importScan->id,
            'library_finding_id' => $importFinding->id,
            'source_relative_path' => 'incoming/original.mkv',
            'relocation_proof' => [
                'type' => 'inode',
                'size_bytes' => $metadata['size'],
                'device_id' => $metadata['dev'],
                'inode_id' => $metadata['ino'],
            ],
        ],
        'disk_id' => 'movies',
        'relative_path' => $relativePath,
        'size_bytes' => $metadata['size'],
        'container' => 'matroska',
        'duration_milliseconds' => 120_000,
        'video_metadata' => ['codec' => 'h264'],
        'audio_metadata' => ['codec' => 'aac'],
        'probe_snapshot' => ['format' => ['format_name' => 'matroska']],
        'finalized_at' => now(),
    ]);
    $movie->update(['current_media_file_id' => $mediaFile->id]);
    $importFinding->update(['media_file_id' => $mediaFile->id]);

    return [$movie, $mediaFile, $path, $relativePath];
}

beforeEach(function () {
    $this->relocationFilesystem = new Filesystem;
    $this->relocationRoot = storage_path('framework/testing/library-relocation-'.bin2hex(random_bytes(6)));
    $this->relocationFilesystem->makeDirectory($this->relocationRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->relocationRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
    configureRelocationDisks(['movies' => $this->relocationRoot]);
    Cache::clear();
    Http::preventStrayRequests();
    Http::fake(['*/movie/603*' => Http::response(relocationTmdbDetails())]);
});

afterEach(function () {
    $this->relocationFilesystem->deleteDirectory($this->relocationRoot);

    if (isset($this->secondaryRelocationRoot)) {
        $this->relocationFilesystem->deleteDirectory($this->secondaryRelocationRoot);
    }
});

it('pairs and atomically restores an inode-proven imported movie after an actual rename', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    [$movie, $oldMediaFile, $oldPath, $canonicalRelativePath] = createImportedRelocationPrimary(
        $this->relocationRoot,
        $administrator,
    );
    $foundRelativePath = 'Moved copy [tmdbid-603].mkv';
    $foundPath = $this->relocationRoot.'/'.$foundRelativePath;
    rename($oldPath, $foundPath);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMovieLibrary($scan->id), 'handle']);

    $discovered = $scan->findings()->where('kind', 'discovered')->sole();
    $missing = $scan->findings()->where('kind', 'missing')->sole();
    expect($discovered->status)->toBe('restore_ready')
        ->and($discovered->paired_missing_finding_id)->toBe($missing->id);

    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.task_type', 'restore')
            ->where('tasks.0.tracked_source.relative_path', $canonicalRelativePath)
            ->where('progress.completed', 0)
            ->where('progress.total', 1));

    $ordinaryUser = User::factory()->create();
    $this->actingAs($ordinaryUser)
        ->post(route('library_findings.restore', $discovered))
        ->assertForbidden();
    $this->actingAs($administrator)
        ->post(route('library_findings.restore', $discovered))
        ->assertRedirect();
    Queue::assertPushed(
        ImportLibraryFinding::class,
        fn (ImportLibraryFinding $job): bool => $job->findingId === $discovered->id,
    );

    app(LibraryImportProcessor::class)->process($discovered->refresh(), $administrator);
    app(LibraryImportProcessor::class)->process($discovered->refresh(), $administrator);

    $newMediaFile = $movie->refresh()->currentMediaFile()->sole();
    expect($foundPath)->not->toBeFile()
        ->and($this->relocationRoot.'/'.$canonicalRelativePath)->toBeFile()
        ->and(MediaFile::query()->count())->toBe(2)
        ->and($oldMediaFile->refresh()->removal_reason)->toBe('relocated')
        ->and($newMediaFile->id)->not->toBe($oldMediaFile->id)
        ->and($newMediaFile->import_provenance['previous_media_file_id'])->toBe($oldMediaFile->id)
        ->and($newMediaFile->import_provenance['library_finding_id'])->toBe($discovered->id)
        ->and($newMediaFile->import_provenance['missing_finding_id'])->toBe($missing->id)
        ->and($discovered->refresh()->resolution)->toBe('relocated')
        ->and($missing->refresh()->resolution)->toBe('relocated');

    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress.completed', 1)
            ->where('progress.total', 1)
            ->has('history', 2)
            ->where('history.0.outcome', 'relocated'));
});

it('proves uploaded bytes by bounded fingerprints across disks and rejects altered bytes', function (bool $altered) {
    $this->secondaryRelocationRoot = storage_path('framework/testing/library-relocation-secondary-'.bin2hex(random_bytes(6)));
    $this->relocationFilesystem->makeDirectory($this->secondaryRelocationRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->secondaryRelocationRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('archive'));
    configureRelocationDisks([
        'movies' => $this->relocationRoot,
        'archive' => $this->secondaryRelocationRoot,
    ]);
    $administrator = User::factory()->create(['is_administrator' => true]);
    $movie = MediaItem::factory()->create([
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'title' => 'The Matrix',
        'release_year' => 1999,
    ]);
    $bytes = '0123456789abcdef';
    $upload = Upload::factory()->for($movie)->for($administrator)->create([
        'disk_id' => 'movies',
        'target_relative_path' => 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv',
        'staging_relative_path' => '.media-upload-manager/incoming/upload.part',
        'declared_size' => strlen($bytes),
        'fingerprint_first_sha256' => hash('sha256', substr($bytes, 0, 4)),
        'fingerprint_last_sha256' => hash('sha256', substr($bytes, -4)),
    ]);
    $transitions = app(TransitionUploadStatus::class);
    $upload = $transitions->asSystem($upload, UploadStatus::Uploading);
    $upload = $transitions->asSystem($upload, UploadStatus::Processing);
    $upload = $transitions->asSystem($upload, UploadStatus::Completed);
    $oldMediaFile = MediaFile::factory()->forUpload($upload)->create([
        'size_bytes' => strlen($bytes),
    ]);
    $movie->update(['current_media_file_id' => $oldMediaFile->id]);
    $foundBytes = $altered ? 'X123456789abcdeY' : $bytes;
    file_put_contents($this->secondaryRelocationRoot.'/Found [tmdbid-603].mkv', $foundBytes);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMovieLibrary($scan->id), 'handle']);

    $discovered = $scan->findings()->where('kind', 'discovered')->sole();
    expect($discovered->status)->toBe($altered ? 'conflict' : 'restore_ready')
        ->and($discovered->paired_missing_finding_id === null)->toBe($altered);

    if (! $altered) {
        app(LibraryImportProcessor::class)->process($discovered, $administrator);
        $restored = $movie->refresh()->currentMediaFile()->sole();

        expect($restored->disk_id)->toBe('archive')
            ->and($restored->import_provenance['relocation_proof']['type'])->toBe('upload_fingerprint')
            ->and($oldMediaFile->refresh()->removal_reason)->toBe('relocated');
    }
})->with([
    'matching cross-disk fingerprint' => false,
    'altered first and last windows' => true,
]);

it('rejects a same-size copied import and keeps the findings independent', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    [, , $oldPath] = createImportedRelocationPrimary($this->relocationRoot, $administrator);
    $copyPath = $this->relocationRoot.'/Copy [tmdbid-603].mkv';
    file_put_contents($copyPath, 'movie-bytes');

    expect(fileinode($copyPath))->not->toBe(fileinode($oldPath));

    unlink($oldPath);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMovieLibrary($scan->id), 'handle']);

    expect($scan->findings()->where('kind', 'discovered')->sole()->status)->toBe('conflict')
        ->and($scan->findings()->where('kind', 'discovered')->sole()->paired_missing_finding_id)->toBeNull()
        ->and($scan->findings()->where('kind', 'missing')->sole()->status)->toBe('missing');
});

it('previews an untagged proven move without mutation and queues restore after identity selection', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    [, , $oldPath] = createImportedRelocationPrimary($this->relocationRoot, $administrator);
    rename($oldPath, $this->relocationRoot.'/Unknown movie.mkv');
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);
    app()->call([new ScanMovieLibrary($scan->id), 'handle']);
    $discovered = $scan->findings()->where('kind', 'discovered')->sole();
    $before = $discovered->attributesToArray();

    $preview = $this->actingAs($administrator)->getJson(route('library_findings.identity_preview', [
        'libraryFinding' => $discovered,
        'tmdb_id' => 603,
    ]))->assertSuccessful()
        ->assertJsonPath('data.operation', 'restore')
        ->assertJsonPath('data.can_import', true)
        ->assertJsonPath('data.relocation.relative_path', 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv')
        ->assertJsonPath('data.blocker', null);

    expect($discovered->fresh()->attributesToArray())->toBe($before);

    $this->actingAs($administrator)->post(route('library_findings.identify_import', $discovered), [
        'tmdb_id' => 603,
        'destination_relative_path' => $preview->json('data.destination.relative_path'),
    ])->assertRedirect();

    expect($discovered->refresh()->status)->toBe('restore_queued')
        ->and($discovered->paired_missing_finding_id)->not->toBeNull();
    Queue::assertPushed(ImportLibraryFinding::class);
});

it('surfaces restore failures as one retryable logical task and counts active restores once', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    [, , $oldPath] = createImportedRelocationPrimary($this->relocationRoot, $administrator);
    rename($oldPath, $this->relocationRoot.'/Moved [tmdbid-603].mkv');
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);
    app()->call([new ScanMovieLibrary($scan->id), 'handle']);
    $discovered = $scan->findings()->where('kind', 'discovered')->sole();
    $discovered->update([
        'status' => 'failed',
        'operation_claim' => ['type' => 'restore'],
        'error_detail' => 'Link interrupted.',
    ]);

    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.task_type', 'retry_restore')
            ->where('tasks.0.error_detail', 'Link interrupted.')
            ->where('processing_count', 0)
            ->where('progress.total', 1));

    $discovered->update(['status' => 'restoring']);

    $this->actingAs($administrator)->get(route('library_scans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 0)
            ->where('processing_count', 1)
            ->where('progress.total', 1));
});

it('unpairs when the old path returns before a claim and blocks external reconciliation while paired', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    [, , $oldPath] = createImportedRelocationPrimary($this->relocationRoot, $administrator);
    $foundPath = $this->relocationRoot.'/Moved [tmdbid-603].mkv';
    rename($oldPath, $foundPath);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);
    app()->call([new ScanMovieLibrary($scan->id), 'handle']);
    $discovered = $scan->findings()->where('kind', 'discovered')->sole();
    $missing = $scan->findings()->where('kind', 'missing')->sole();

    expect(fn () => app(ReconcileMissingMediaFile::class)->execute($missing, $administrator, true))
        ->toThrow(RuntimeException::class, 'proven moved-file task');

    link($foundPath, $oldPath);

    expect(fn () => app(LibraryImportProcessor::class)->process($discovered, $administrator))
        ->toThrow(RuntimeException::class, 'returned')
        ->and($missing->refresh()->resolution)->toBe('restored')
        ->and($discovered->refresh()->paired_missing_finding_id)->toBeNull()
        ->and($discovered->status)->toBe('conflict');
});
