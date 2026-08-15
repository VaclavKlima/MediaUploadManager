<?php

use App\Enums\MediaRootKind;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function configureMediaCommands(array $disks): void
{
    config()->set('media', [
        'disks' => $disks,
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->workspace = storage_path('framework/testing/command-'.bin2hex(random_bytes(6)));
    $this->root = $this->workspace.'/movies';
    $this->seriesRoot = $this->workspace.'/series';
    $this->filesystem->makeDirectory($this->root, 0750, true);
    $this->filesystem->makeDirectory($this->seriesRoot, 0750, true);
    configureMediaCommands([
        ['id' => 'movies', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->workspace);
});

it('confirms the selected label and root before initialization', function () {
    file_put_contents($this->root.'/existing-movie.mkv', 'movie bytes');

    $this->artisan('media:disks:initialize', ['disk' => 'movies'])
        ->expectsOutputToContain('Movies')
        ->expectsOutputToContain('Kind: movies')
        ->expectsOutputToContain($this->root)
        ->expectsConfirmation('Initialize this media disk?', 'yes')
        ->assertSuccessful();

    $marker = DiskMarker::parse((string) file_get_contents($this->root.'/.media-upload-manager/disk.json'));

    expect(file_get_contents($this->root.'/existing-movie.mkv'))->toBe('movie bytes')
        ->and($marker?->version)->toBe(2)
        ->and($marker?->diskId)->toBe('movies')
        ->and($marker?->kind)->toBe(MediaRootKind::Movies);
});

it('cancels initialization without writing', function () {
    $this->artisan('media:disks:initialize', ['disk' => 'movies'])
        ->expectsConfirmation('Initialize this media disk?', 'no')
        ->assertSuccessful();

    expect(file_exists($this->root.'/.media-upload-manager'))->toBeFalse();
});

it('supports idempotent unattended initialization', function () {
    $arguments = ['disk' => 'movies', '--no-interaction' => true];

    $this->artisan('media:disks:initialize', $arguments)->assertSuccessful();
    $this->artisan('media:disks:initialize', $arguments)->assertSuccessful();
});

it('initializes an explicitly selected Series root', function () {
    configureMediaCommands([[
        'id' => 'media',
        'label' => 'Media',
        'movies_path' => $this->root,
        'series_path' => $this->seriesRoot,
        'reserve_gib' => '0',
    ]]);

    $this->artisan('media:disks:initialize', [
        'disk' => 'media',
        '--kind' => 'series',
        '--no-interaction' => true,
    ])->expectsOutputToContain('series')->assertSuccessful();

    $marker = DiskMarker::parse((string) file_get_contents($this->seriesRoot.'/.media-upload-manager/disk.json'));

    expect($marker?->diskId)->toBe('media')
        ->and($marker?->kind)->toBe(MediaRootKind::Series)
        ->and(file_exists($this->root.'/.media-upload-manager'))->toBeFalse();
});

it('rejects invalid and unconfigured root kinds without writing', function () {
    $this->artisan('media:disks:initialize', [
        'disk' => 'movies',
        '--kind' => 'shows',
        '--no-interaction' => true,
    ])->assertExitCode(2);

    $this->artisan('media:disks:initialize', [
        'disk' => 'movies',
        '--kind' => 'series',
        '--no-interaction' => true,
    ])->assertExitCode(2);

    expect(file_exists($this->root.'/.media-upload-manager'))->toBeFalse();
});

it('reports when a legacy Movie marker is upgraded', function () {
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->root.'/.media-upload-manager/disk.json',
        DiskMarker::encodeLegacy('movies'),
    );

    $this->artisan('media:disks:initialize', [
        'disk' => 'movies',
        '--no-interaction' => true,
    ])->expectsOutputToContain('Legacy Movie marker upgraded')->assertSuccessful();
});

it('refuses to create a missing configured root', function () {
    $missingRoot = $this->root.'/missing';
    configureMediaCommands([
        ['id' => 'missing', 'label' => 'Missing', 'path' => $missingRoot, 'reserve_gib' => '0'],
    ]);

    $this->artisan('media:disks:initialize', ['disk' => 'missing', '--no-interaction' => true])
        ->assertFailed();

    expect(file_exists($missingRoot))->toBeFalse();
});

it('refuses to overwrite a conflicting marker', function () {
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager', 0750);
    $markerPath = $this->root.'/.media-upload-manager/disk.json';
    $contents = DiskMarker::encode('other');
    file_put_contents($markerPath, $contents);

    $this->artisan('media:disks:initialize', ['disk' => 'movies', '--no-interaction' => true])
        ->assertFailed();

    expect(file_get_contents($markerPath))->toBe($contents);
});

it('emits safe JSON and succeeds for healthy initialized disks', function () {
    $this->artisan('media:disks:initialize', ['disk' => 'movies', '--no-interaction' => true])
        ->assertSuccessful();

    $exitCode = Artisan::call('media:disks:check', ['--json' => true]);
    $output = trim(Artisan::output());
    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($decoded['data'][0]['id'])->toBe('movies')
        ->and($decoded['data'][0]['kind'])->toBe('movies')
        ->and($decoded['data'][0]['health'])->toBe('healthy')
        ->and($output)->not->toContain($this->root);
});

it('checks every configured root by default and supports a kind filter', function () {
    configureMediaCommands([[
        'id' => 'media',
        'label' => 'Media',
        'movies_path' => $this->root,
        'series_path' => $this->seriesRoot,
        'reserve_gib' => '0',
    ]]);
    $this->artisan('media:disks:initialize', [
        'disk' => 'media',
        '--kind' => 'movies',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->artisan('media:disks:check')->assertFailed();
    $this->artisan('media:disks:check', ['--kind' => 'movies'])->assertSuccessful();

    $this->artisan('media:disks:initialize', [
        'disk' => 'media',
        '--kind' => 'series',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $exitCode = Artisan::call('media:disks:check', ['--json' => true]);
    $output = trim(Artisan::output());
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['data'];

    expect($exitCode)->toBe(0)
        ->and(array_column($data, 'kind'))->toBe(['movies', 'series'])
        ->and($output)->not->toContain($this->root, $this->seriesRoot);
});

it('rejects an invalid health-check kind safely in JSON', function () {
    $exitCode = Artisan::call('media:disks:check', ['--kind' => 'shows', '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(2)
        ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'invalid_kind',
                'message' => 'The root kind must be either movies or series.',
            ],
        ])
        ->and($output)->not->toContain($this->root);
});

it('exits nonzero when any configured disk is unhealthy', function () {
    $this->artisan('media:disks:check')->assertFailed();
});

it('reports invalid configuration safely in JSON', function () {
    configureMediaCommands([
        ['id' => 'INVALID', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);

    $exitCode = Artisan::call('media:disks:check', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(2)
        ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'invalid_configuration',
                'message' => 'Media disk configuration is invalid.',
            ],
        ])
        ->and($output)->not->toContain($this->root);
});

it('allows a local installation with no configured disks', function () {
    configureMediaCommands([]);

    $this->artisan('media:disks:check')->assertSuccessful();
});
