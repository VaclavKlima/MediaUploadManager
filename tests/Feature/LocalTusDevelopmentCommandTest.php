<?php

use App\Enums\MediaRootKind;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\LocalTusDevelopmentEnvironment;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function localTusArchive(Filesystem $filesystem, string $root): string
{
    $archivePath = $root.'/fixture.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('tusd', '#!/bin/sh'."\n".'echo tusd fixture'."\n");
    $zip->close();

    return $filesystem->get($archivePath);
}

function configureLocalTusCommand(string $movieRoot, string $seriesRoot, string $runtimePath): void
{
    config()->set('app.env', 'local');
    config()->set('app.url', 'https://media-upload-manager.test');
    config()->set('media', [
        'disks' => [[
            'id' => 'movies',
            'label' => 'Media',
            'movies_path' => $movieRoot,
            'series_path' => $seriesRoot,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload', [
        'tus_public_path' => '/uploads/tus/',
        'tus_internal_url' => 'http://127.0.0.1:1080/uploads/tus/',
        'hook_secret' => str_repeat('h', 64),
        'chunk_size_bytes' => '67108864',
        'retry_delays_milliseconds' => '0,3000,5000,10000,20000',
        'internal_connect_timeout_seconds' => '2',
        'internal_timeout_seconds' => '5',
        'token_ttl_seconds' => '900',
        'token_refresh_leeway_seconds' => '60',
        'inactivity_seconds' => '604800',
        'fingerprint_window_bytes' => '1048576',
        'local_tus_runtime_path' => $runtimePath,
    ]);

    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->root = storage_path('framework/testing/local-tus-'.bin2hex(random_bytes(6)));
    $this->diskRoot = $this->root.'/disk';
    $this->seriesRoot = $this->root.'/series';
    $this->runtimePath = $this->root.'/runtime';
    $this->herdConfigurationPath = $this->root.'/media-upload-manager.test';
    $this->filesystem->makeDirectory($this->diskRoot, 0750, true);
    $this->filesystem->makeDirectory($this->seriesRoot, 0750, true);
    $this->filesystem->put($this->herdConfigurationPath, <<<'NGINX'
server {
    listen 127.0.0.1:443 ssl;

    location / {
        return 200;
    }
}
NGINX);
    configureLocalTusCommand($this->diskRoot, $this->seriesRoot, $this->runtimePath);
});

afterEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
    $this->filesystem->deleteDirectory($this->root);
});

it('prepares an idempotent pinned local tusd and secured Herd proxy', function () {
    $archive = localTusArchive($this->filesystem, $this->root);
    Http::preventStrayRequests();
    Http::fake([
        'https://github.com/tus/tusd/releases/download/v2.10.0/tusd_darwin_arm64.zip' => Http::response($archive),
        'https://github.com/tus/tusd/releases/download/v2.10.0/tusd_darwin_arm64.zip.sha256' => Http::response(hash('sha256', $archive).'  tusd_darwin_arm64.zip'),
    ]);
    Process::preventStrayProcesses();
    Process::fake(fn () => Process::result());

    $arguments = [
        '--prepare-only' => true,
        '--force' => true,
        '--herd-config' => $this->herdConfigurationPath,
    ];

    $this->artisan('upload:dev', $arguments)
        ->expectsOutputToContain('Installed pinned tusd 2.10.0')
        ->expectsOutputToContain('restarted Herd')
        ->assertSuccessful();

    $movieMarker = DiskMarker::parse($this->filesystem->get($this->diskRoot.'/.media-upload-manager/disk.json'));
    $seriesMarker = DiskMarker::parse($this->filesystem->get($this->seriesRoot.'/.media-upload-manager/disk.json'));

    expect($movieMarker?->kind)->toBe(MediaRootKind::Movies)
        ->and($seriesMarker?->kind)->toBe(MediaRootKind::Series)
        ->and($seriesMarker?->diskId)->toBe('movies')
        ->and(is_executable($this->runtimePath.'/bin/tusd'))->toBeTrue()
        ->and($this->filesystem->get($this->runtimePath.'/bin/version'))->toBe("2.10.0\n")
        ->and($this->filesystem->get($this->runtimePath.'/herd-hooks.conf'))
        ->toContain('X-Tus-Hook-Secret "'.str_repeat('h', 64).'"')
        ->and($this->filesystem->get($this->herdConfigurationPath))
        ->toContain('# MUM_TUS_PUBLIC_INCLUDE')
        ->toContain('# MUM_TUS_HOOK_INCLUDE')
        ->and($this->filesystem->exists($this->herdConfigurationPath.'.before-mum-tus'))->toBeTrue();

    $tusdCommand = app(LocalTusDevelopmentEnvironment::class)
        ->tusdCommand(app(UploadConfiguration::class));

    expect($tusdCommand)
        ->toContain('-upload-dir='.$this->runtimePath.'/metadata')
        ->toContain('-hooks-http-backoff=1s')
        ->not->toContain('-enable-termination')
        ->not->toContain('-disable-termination')
        ->not->toContain('-dir='.$this->runtimePath.'/metadata');

    $this->artisan('upload:dev', $arguments)->assertSuccessful();

    expect(substr_count($this->filesystem->get($this->herdConfigurationPath), '# MUM_TUS_PUBLIC_INCLUDE'))->toBe(1)
        ->and(substr_count($this->filesystem->get($this->herdConfigurationPath), '# MUM_TUS_HOOK_INCLUDE'))->toBe(1)
        ->and(Artisan::output())->not->toContain(str_repeat('h', 64));

    Http::assertSentCount(2);
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => $process->command === ['herd', 'restart'],
        1,
    );
})->onlyOnMac();

it('refuses non-local execution before writing disk or Herd state', function () {
    app()->detectEnvironment(fn (): string => 'production');
    app()->forgetInstance(UploadConfiguration::class);

    $this->artisan('upload:dev', [
        '--prepare-only' => true,
        '--force' => true,
        '--herd-config' => $this->herdConfigurationPath,
    ])->assertExitCode(2);

    expect($this->filesystem->exists($this->diskRoot.'/.media-upload-manager'))->toBeFalse()
        ->and($this->filesystem->exists($this->seriesRoot.'/.media-upload-manager'))->toBeFalse()
        ->and($this->filesystem->get($this->herdConfigurationPath))->not->toContain('MUM_TUS');
});
