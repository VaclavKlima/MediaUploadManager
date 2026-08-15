<?php

use App\Enums\DiskInitializationResult;
use App\Enums\MediaRootKind;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MountPointChecker;
use App\Support\Media\DiskMarker;
use App\Support\Media\Exceptions\DiskInitializationException;
use App\Support\Media\MediaDiskInitializer;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\MountPointInspection;
use App\Support\Media\NativeMediaFilesystem;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->root = getcwd().'/storage/framework/testing/initializer-'.bin2hex(random_bytes(6));
    $this->filesystem->makeDirectory($this->root, 0750, true);
    $this->nativeFilesystem = new NativeMediaFilesystem;
    $this->mountChecker = new class implements MountPointChecker
    {
        public MountPointInspection $inspection;

        public function __construct()
        {
            $this->inspection = MountPointInspection::detected(true);
        }

        public function inspect(string $resolvedRoot): MountPointInspection
        {
            return $this->inspection;
        }
    };
    $this->initializer = new MediaDiskInitializer(
        $this->nativeFilesystem,
        new MediaPathGuard($this->nativeFilesystem),
        $this->mountChecker,
    );
    $this->disk = new ConfiguredMediaDisk('movies', 'Movies', $this->root, 0);
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->root);
});

it('creates only the private tree and marker while preserving existing movies', function () {
    $movieDirectory = $this->root.'/Existing Movie (2020)';
    $this->filesystem->makeDirectory($movieDirectory, 0750);
    file_put_contents($movieDirectory.'/Existing Movie (2020).mkv', 'movie bytes');

    $result = $this->initializer->initialize($this->disk, false);
    $marker = DiskMarker::parse((string) file_get_contents($this->root.'/.media-upload-manager/disk.json'));

    expect($result)->toBe(DiskInitializationResult::Created)
        ->and(file_get_contents($movieDirectory.'/Existing Movie (2020).mkv'))->toBe('movie bytes')
        ->and(is_dir($this->root.'/.media-upload-manager/incoming'))->toBeTrue()
        ->and($marker)->toBeInstanceOf(DiskMarker::class)
        ->and($marker?->version)->toBe(2)
        ->and($marker?->diskId)->toBe('movies')
        ->and($marker?->kind)->toBe(MediaRootKind::Movies);

    $entries = array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
    sort($entries);

    expect($entries)->toBe(['.media-upload-manager', 'Existing Movie (2020)']);
});

it('is idempotent for a matching marker', function () {
    expect($this->initializer->initialize($this->disk, false))->toBe(DiskInitializationResult::Created)
        ->and($this->initializer->initialize($this->disk, false))->toBe(DiskInitializationResult::AlreadyInitialized);
});

it('creates and validates a version 2 Series marker independently', function () {
    $seriesDisk = new ConfiguredMediaDisk(
        'movies',
        'Media',
        $this->root,
        0,
        MediaRootKind::Series,
    );

    expect($this->initializer->initialize($seriesDisk, false))->toBe(DiskInitializationResult::Created);

    $marker = DiskMarker::parse((string) file_get_contents($this->root.'/.media-upload-manager/disk.json'));

    expect($marker?->version)->toBe(2)
        ->and($marker?->diskId)->toBe('movies')
        ->and($marker?->kind)->toBe(MediaRootKind::Series);
});

it('atomically upgrades a matching legacy Movie marker', function () {
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager/incoming', 0750, true);
    $markerPath = $this->root.'/.media-upload-manager/disk.json';
    file_put_contents($markerPath, DiskMarker::encodeLegacy('movies'));

    expect($this->initializer->initialize($this->disk, false))->toBe(DiskInitializationResult::Upgraded);

    $marker = DiskMarker::parse((string) file_get_contents($markerPath));

    expect($marker?->version)->toBe(2)
        ->and($marker?->kind)->toBe(MediaRootKind::Movies)
        ->and(glob($markerPath.'.upgrade-*') ?: [])->toBe([]);
});

it('accepts no legacy marker for a Series root and changes nothing', function () {
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager', 0750);
    $markerPath = $this->root.'/.media-upload-manager/disk.json';
    $legacyMarker = DiskMarker::encodeLegacy('movies');
    file_put_contents($markerPath, $legacyMarker);
    $seriesDisk = new ConfiguredMediaDisk('movies', 'Media', $this->root, 0, MediaRootKind::Series);

    expect(fn () => $this->initializer->initialize($seriesDisk, false))
        ->toThrow(DiskInitializationException::class)
        ->and(file_get_contents($markerPath))->toBe($legacyMarker)
        ->and(is_dir($this->root.'/.media-upload-manager/incoming'))->toBeFalse();
});

it('preserves the legacy marker when an atomic upgrade fails and cleans its temporary file', function () {
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager/incoming', 0750, true);
    $markerPath = $this->root.'/.media-upload-manager/disk.json';
    $legacyMarker = DiskMarker::encodeLegacy('movies');
    file_put_contents($markerPath, $legacyMarker);
    $failingFilesystem = new class extends NativeMediaFilesystem
    {
        public function replaceFileAtomically(string $source, string $target): bool
        {
            return false;
        }
    };
    $initializer = new MediaDiskInitializer(
        $failingFilesystem,
        new MediaPathGuard($failingFilesystem),
        $this->mountChecker,
    );

    expect(fn () => $initializer->initialize($this->disk, false))
        ->toThrow(DiskInitializationException::class)
        ->and(file_get_contents($markerPath))->toBe($legacyMarker)
        ->and(glob($markerPath.'.upgrade-*') ?: [])->toBe([]);
});

it('never creates a missing configured root', function () {
    $missingRoot = $this->root.'/missing';
    $disk = new ConfiguredMediaDisk('missing', 'Missing', $missingRoot, 0);

    expect(fn () => $this->initializer->initialize($disk, false))
        ->toThrow(DiskInitializationException::class)
        ->and(file_exists($missingRoot))->toBeFalse();
});

it('does not overwrite malformed or mismatched markers', function (string $contents) {
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager', 0750);
    $markerPath = $this->root.'/.media-upload-manager/disk.json';
    file_put_contents($markerPath, $contents);

    expect(fn () => $this->initializer->initialize($this->disk, false))
        ->toThrow(DiskInitializationException::class)
        ->and(file_get_contents($markerPath))->toBe($contents)
        ->and(is_dir($this->root.'/.media-upload-manager/incoming'))->toBeFalse();
})->with([
    'malformed' => '{not-json',
    'mismatched' => DiskMarker::encode('other'),
    'wrong kind' => DiskMarker::encode('movies', MediaRootKind::Series),
]);

it('fails closed when a production root is not an exact mount point', function () {
    $this->mountChecker->inspection = MountPointInspection::detected(false);

    expect(fn () => $this->initializer->initialize($this->disk, true))
        ->toThrow(DiskInitializationException::class)
        ->and(file_exists($this->root.'/.media-upload-manager'))->toBeFalse();
});

it('fails closed when production mount information is unavailable', function () {
    $this->mountChecker->inspection = MountPointInspection::unavailable();

    expect(fn () => $this->initializer->initialize($this->disk, true))
        ->toThrow(DiskInitializationException::class)
        ->and(file_exists($this->root.'/.media-upload-manager'))->toBeFalse();
});
