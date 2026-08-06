<?php

use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MountPointChecker;
use App\Support\Media\DiskHealthReason;
use App\Support\Media\DiskMarker;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\MountPointInspection;
use App\Support\Media\NativeMediaFilesystem;
use Illuminate\Filesystem\Filesystem;

function healthCheckerFor(NativeMediaFilesystem $filesystem): MediaDiskHealthChecker
{
    $mountChecker = new class implements MountPointChecker
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

    return new MediaDiskHealthChecker($filesystem, new MediaPathGuard($filesystem), $mountChecker);
}

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->root = getcwd().'/storage/framework/testing/health-'.bin2hex(random_bytes(6));
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->root.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->root);
});

it('reports deterministic capacity and eligibility independently from health', function () {
    $nativeFilesystem = new class extends NativeMediaFilesystem
    {
        public function capacity(string $path): ?array
        {
            return ['total' => 10_000, 'free' => 7_000];
        }
    };
    $disk = new ConfiguredMediaDisk('movies', 'Movies', $this->root, 2_000);
    $status = healthCheckerFor($nativeFilesystem)->check($disk, false);

    expect($status)
        ->healthy->toBeTrue()
        ->eligible->toBeTrue()
        ->totalBytes->toBe(10_000)
        ->freeBytes->toBe(7_000)
        ->usableBytes->toBe(5_000)
        ->safetyReserveBytes->toBe(2_000)
        ->reasons->toBe([]);

    expect(glob($this->root.'/.media-upload-manager/incoming/.health-*') ?: [])->toBe([]);
});

it('keeps a below-reserve disk healthy but ineligible', function () {
    $nativeFilesystem = new class extends NativeMediaFilesystem
    {
        public function capacity(string $path): ?array
        {
            return ['total' => 10_000, 'free' => 1_000];
        }
    };
    $status = healthCheckerFor($nativeFilesystem)->check(
        new ConfiguredMediaDisk('movies', 'Movies', $this->root, 2_000),
        false,
    );

    expect($status)
        ->healthy->toBeTrue()
        ->eligible->toBeFalse()
        ->usableBytes->toBe(0)
        ->and($status->reasons)->toBe([DiskHealthReason::BelowSafetyReserve]);
});

it('reports marker and staging failures with allowlisted reasons', function (string $setup, DiskHealthReason $reason) {
    $markerPath = $this->root.'/.media-upload-manager/disk.json';
    $incomingPath = $this->root.'/.media-upload-manager/incoming';

    match ($setup) {
        'missing_marker' => unlink($markerPath),
        'invalid_marker' => file_put_contents($markerPath, '{bad-json'),
        'mismatched_marker' => file_put_contents($markerPath, DiskMarker::encode('other')),
        'missing_incoming' => $this->filesystem->deleteDirectory($incomingPath),
    };

    $status = healthCheckerFor(new NativeMediaFilesystem)->check(
        new ConfiguredMediaDisk('movies', 'Movies', $this->root, 0),
        false,
    );

    expect($status->healthy)->toBeFalse()
        ->and($status->eligible)->toBeFalse()
        ->and($status->reasons)->toBe([$reason]);
})->with([
    'missing marker' => ['missing_marker', DiskHealthReason::MarkerMissing],
    'invalid marker' => ['invalid_marker', DiskHealthReason::MarkerInvalid],
    'mismatched marker' => ['mismatched_marker', DiskHealthReason::MarkerMismatch],
    'missing incoming' => ['missing_incoming', DiskHealthReason::IncomingMissing],
]);

it('reports read and write permission failures without probing', function () {
    $nativeFilesystem = new class extends NativeMediaFilesystem
    {
        public bool $probed = false;

        public function isReadable(string $path): bool
        {
            return ! str_ends_with($path, '/incoming');
        }

        public function isWritable(string $path): bool
        {
            return ! str_ends_with($path, '/incoming');
        }

        public function probe(string $directory): bool
        {
            $this->probed = true;

            return true;
        }
    };
    $status = healthCheckerFor($nativeFilesystem)->check(
        new ConfiguredMediaDisk('movies', 'Movies', $this->root, 0),
        false,
    );

    expect($status->reasons)->toBe([
        DiskHealthReason::IncomingUnreadable,
        DiskHealthReason::IncomingUnwritable,
    ])->and($nativeFilesystem->probed)->toBeFalse();
});

it('reports probe and capacity failures without leaking exceptions', function (string $failure, DiskHealthReason $reason) {
    $nativeFilesystem = new class($failure) extends NativeMediaFilesystem
    {
        public function __construct(private readonly string $failure) {}

        public function capacity(string $path): ?array
        {
            return $this->failure === 'capacity' ? null : ['total' => 10_000, 'free' => 9_000];
        }

        public function probe(string $directory): bool
        {
            return $this->failure !== 'probe';
        }
    };
    $status = healthCheckerFor($nativeFilesystem)->check(
        new ConfiguredMediaDisk('movies', 'Movies', $this->root, 0),
        false,
    );

    expect($status->healthy)->toBeFalse()
        ->and($status->reasons)->toContain($reason);
})->with([
    'probe' => ['probe', DiskHealthReason::ProbeFailed],
    'capacity' => ['capacity', DiskHealthReason::CapacityUnavailable],
]);

it('reports a missing root as unhealthy', function () {
    $missingRoot = $this->root.'/missing';
    $status = healthCheckerFor(new NativeMediaFilesystem)->check(
        new ConfiguredMediaDisk('movies', 'Movies', $missingRoot, 0),
        false,
    );

    expect($status->reasons)->toBe([DiskHealthReason::RootMissing]);
});
