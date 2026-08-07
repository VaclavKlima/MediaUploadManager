<?php

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use Illuminate\Filesystem\Filesystem;

/** @param list<array<string, mixed>> $disks */
function configurePathPreviewDisks(array $disks): void
{
    config()->set('media', [
        'disks' => $disks,
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

/** @return array{directories: list<string>, files: list<string>} */
function pathPreviewTree(Filesystem $filesystem, string $root): array
{
    $directories = $filesystem->allDirectories($root);
    $files = array_map(
        fn (SplFileInfo $file): string => $file->getPathname(),
        $filesystem->allFiles($root),
    );
    sort($directories);
    sort($files);

    return [
        'directories' => $directories,
        'files' => $files,
    ];
}

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->previewBase = storage_path('framework/testing/path-preview-'.bin2hex(random_bytes(6)));
    $this->previewA = $this->previewBase.'/a';
    $this->previewB = $this->previewBase.'/b';
    $this->filesystem->makeDirectory($this->previewA, 0750, true);
    $this->filesystem->makeDirectory($this->previewB, 0750, true);
    $this->filesystem->makeDirectory($this->previewA.'/.media-upload-manager/incoming', 0750, true);
    $this->filesystem->makeDirectory($this->previewB.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->previewA.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_a'));
    file_put_contents($this->previewB.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_b'));
    configurePathPreviewDisks([
        ['id' => 'movies_b', 'label' => 'Movies B', 'path' => $this->previewB],
        ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $this->previewA],
    ]);
    $this->previewMovie = MediaItem::factory()->create([
        'title' => 'Amélie',
        'release_year' => 2001,
        'tmdb_id' => 194,
    ]);
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->previewBase);
});

it('requires authentication for path previews', function () {
    $this->get(route('movies.path_preview', [
        'mediaItem' => $this->previewMovie,
        'filename' => 'amelie.mkv',
        'declared_size' => 1_000,
    ]))->assertRedirect(route('login'));
});

it('returns a safe route-binding 404 for an unknown movie', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => 999_999_999,
            'filename' => 'movie.mkv',
            'declared_size' => 1_000,
        ]))
        ->assertNotFound();

    expect($response->getContent())->not->toContain($this->previewBase);
});

it('validates the source filename and safely rejects invalid previews', function (string $filename, ?string $validationField) {
    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => $filename,
            'declared_size' => 1_000,
        ]))
        ->assertUnprocessable();

    if ($validationField !== null) {
        $response->assertJsonValidationErrorFor($validationField);
    } else {
        $response->assertExactJson([
            'error' => 'path_preview_invalid',
            'message' => 'A destination preview cannot be built from this movie and filename.',
        ]);
    }
})->with([
    'empty' => ['', 'filename'],
    'unsupported' => ['movie.exe', null],
    'unsafe basename' => ['../movie.mkv', null],
]);

it('returns the exact canonical preview shape in deterministic disk order', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => 'Amelie.2001.MKV',
            'declared_size' => 1_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.directory', 'Amélie (2001) [tmdbid-194]')
        ->assertJsonPath('data.filename', 'Amélie (2001) [tmdbid-194].mkv')
        ->assertJsonPath('data.relative_path', 'Amélie (2001) [tmdbid-194]/Amélie (2001) [tmdbid-194].mkv')
        ->assertJsonPath('data.extension', 'mkv')
        ->assertJsonPath('data.declared_size', 1_000)
        ->assertJsonPath('data.can_start_new_upload', true)
        ->assertJsonPath('data.recommended_disk_id', 'movies_a')
        ->assertJsonPath('data.fingerprint_window_bytes', 1_048_576)
        ->assertJsonPath('data.blockers', [])
        ->assertJsonPath('data.disks.0.id', 'movies_a')
        ->assertJsonPath('data.disks.0.active_reserved_bytes', 0)
        ->assertJsonPath('data.disks.0.eligible', true)
        ->assertJsonPath('data.disks.1.id', 'movies_b')
        ->assertJsonStructure(['data' => ['disks' => [[
            'id',
            'label',
            'status',
            'health',
            'total_bytes',
            'free_bytes',
            'safety_reserve_bytes',
            'usable_bytes',
            'active_reserved_bytes',
            'projected_usable_bytes',
            'eligible',
            'reasons',
        ]]]]);

    expect($response->getContent())->not->toContain($this->previewA)
        ->not->toContain($this->previewB);
});

it('returns global blocker codes and local disk identity without leaking absolute paths', function () {
    $upload = Upload::factory()->for($this->previewMovie)->create(['disk_id' => 'movies_b']);
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();
    $this->previewMovie->update(['current_media_file_id' => $mediaFile->id]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => 'movie.mp4',
            'declared_size' => 1_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.can_start_new_upload', false)
        ->assertJsonFragment([
            'code' => 'current_primary_exists',
            'message' => 'A current primary already exists for this movie.',
        ])
        ->assertJsonPath('data.blockers.0.disk.id', 'movies_b')
        ->assertJsonPath('data.disks.1.status', 'conflict');

    expect($response->getContent())->not->toContain($this->previewBase);
});

it('subtracts only remaining bytes from reservation-active uploads when recommending a disk', function () {
    $activeUpload = Upload::factory()->create(['disk_id' => 'movies_a']);
    Upload::query()->whereKey($activeUpload)->update([
        'declared_size' => 10_000,
        'confirmed_offset' => 4_000,
    ]);
    $completedUpload = Upload::factory()->create(['disk_id' => 'movies_b']);
    Upload::query()->whereKey($completedUpload)->update([
        'status' => UploadStatus::Completed->value,
        'declared_size' => 50_000,
        'confirmed_offset' => 0,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => 'movie.mkv',
            'declared_size' => 1_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.disks.0.active_reserved_bytes', 6_000)
        ->assertJsonPath('data.disks.1.active_reserved_bytes', 0)
        ->assertJsonPath('data.recommended_disk_id', 'movies_b');

    $usableBytes = $response->json('data.disks.0.usable_bytes');
    expect($response->json('data.disks.0.projected_usable_bytes'))
        ->toBe($usableBytes - 7_000);
});

it('omits unknown database disk identities from safe blockers', function () {
    Upload::factory()->for($this->previewMovie)->create([
        'disk_id' => $this->previewBase.'/unknown-disk',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => 'movie.webm',
            'declared_size' => 1_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.blockers.0.code', 'active_upload_exists')
        ->assertJsonPath('data.blockers.0.disk', null);

    expect($response->getContent())->not->toContain($this->previewBase);
});

it('returns a safe 422 when immutable movie metadata cannot build a path', function () {
    $movieWithoutYear = MediaItem::factory()->create(['release_year' => null]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $movieWithoutYear,
            'filename' => 'movie.mkv',
            'declared_size' => 1_000,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'path_preview_invalid');
});

it('returns a generic safe 503 for invalid disk configuration', function () {
    configurePathPreviewDisks([
        ['id' => 'Movies', 'label' => 'Movies', 'path' => $this->previewA],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => 'movie.mkv',
            'declared_size' => 1_000,
        ]))
        ->assertServiceUnavailable()
        ->assertExactJson([
            'error' => 'media_configuration_unavailable',
            'message' => 'Media disk configuration is unavailable.',
        ]);

    expect($response->getContent())->not->toContain($this->previewA);
});

it('performs no filesystem mutation while checking every target', function () {
    $beforeA = pathPreviewTree($this->filesystem, $this->previewA);
    $beforeB = pathPreviewTree($this->filesystem, $this->previewB);

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->previewMovie,
            'filename' => 'movie.m2ts',
            'declared_size' => 1_000,
        ]))
        ->assertSuccessful();

    expect(pathPreviewTree($this->filesystem, $this->previewA))->toBe($beforeA)
        ->and(pathPreviewTree($this->filesystem, $this->previewB))->toBe($beforeB);
});
