<?php

use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Support\CanonicalJson;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function configureDynamicRangeBackfillDisk(string $root): void
{
    config()->set('media', [
        'disks' => [[
            'id' => 'movies',
            'label' => 'Movies',
            'path' => $root,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload.ffprobe_binary', 'ffprobe');

    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @param array<string, mixed> $videoMetadata */
function createBackfillMediaFile(
    string $root,
    string $relativePath,
    array $videoMetadata,
    string $contents = 'movie-bytes',
    bool $current = true,
): MediaFile {
    $mediaItem = MediaItem::factory()->create();
    $upload = Upload::factory()->for($mediaItem)->create([
        'disk_id' => 'movies',
        'target_relative_path' => $relativePath,
        'declared_size' => strlen($contents),
    ]);
    $mediaFile = MediaFile::factory()->forUpload($upload)->create([
        'disk_id' => 'movies',
        'relative_path' => $relativePath,
        'size_bytes' => strlen($contents),
        'video_metadata' => [$videoMetadata],
    ]);

    if ($current) {
        $mediaItem->update(['current_media_file_id' => $mediaFile->getKey()]);
    }

    $path = $root.'/'.$relativePath;
    (new Filesystem)->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, $contents);

    return $mediaFile;
}

function dynamicRangeProbeJson(): string
{
    return json_encode([
        'streams' => [[
            'index' => 0,
            'codec_name' => 'hevc',
            'codec_type' => 'video',
            'width' => 3840,
            'height' => 1600,
            'color_transfer' => 'smpte2084',
            'side_data_list' => [['side_data_type' => 'HDR Dynamic Metadata SMPTE2094-40']],
            'disposition' => ['default' => 1],
        ]],
        'format' => [
            'format_name' => 'matroska',
            'duration' => '120.000',
        ],
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->dynamicRangeFilesystem = new Filesystem;
    $this->dynamicRangeBase = storage_path('framework/testing/dynamic-range-'.bin2hex(random_bytes(6)));
    $this->dynamicRangeDisk = $this->dynamicRangeBase.'/movies';
    $this->dynamicRangeFilesystem->makeDirectory(
        $this->dynamicRangeDisk.'/.media-upload-manager/incoming',
        0750,
        true,
    );
    file_put_contents(
        $this->dynamicRangeDisk.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies'),
    );
    configureDynamicRangeBackfillDisk($this->dynamicRangeDisk);
    Process::preventStrayProcesses();
});

afterEach(function () {
    $this->dynamicRangeFilesystem->deleteDirectory($this->dynamicRangeBase);
});

it('backfills current files in place, skips enriched rows, and is idempotent', function () {
    $probeCalls = 0;
    Process::fake(function (PendingProcess $process) use (&$probeCalls) {
        $probeCalls++;

        return Process::result(output: dynamicRangeProbeJson());
    });
    $missing = createBackfillMediaFile($this->dynamicRangeDisk, 'Missing/movie.mkv', [
        'index' => 0,
        'codec' => 'hevc',
        'width' => 3840,
        'height' => 1600,
    ]);
    $enriched = createBackfillMediaFile($this->dynamicRangeDisk, 'Enriched/movie.mkv', [
        'index' => 0,
        'codec' => 'h264',
        'width' => 1920,
        'height' => 800,
        'dynamic_range' => 'sdr',
    ]);
    $historical = createBackfillMediaFile($this->dynamicRangeDisk, 'Historical/movie.mkv', [
        'index' => 0,
        'codec' => 'hevc',
        'width' => 3840,
        'height' => 1600,
    ], current: false);

    $this->artisan('media:metadata:backfill-dynamic-range')
        ->expectsOutputToContain('1 enriched, 1 skipped, 0 failed')
        ->assertSuccessful();

    expect(CanonicalJson::equivalent($missing->refresh()->video_metadata, [[
        'index' => 0,
        'codec' => 'hevc',
        'width' => 3840,
        'height' => 1600,
        'dynamic_range' => 'hdr10_plus',
    ]]))->toBeTrue()
        ->and($enriched->refresh()->video_metadata[0]['dynamic_range'])->toBe('sdr')
        ->and($historical->refresh()->video_metadata[0])->not->toHaveKey('dynamic_range')
        ->and($probeCalls)->toBe(1);

    $this->artisan('media:metadata:backfill-dynamic-range')
        ->expectsOutputToContain('0 enriched, 2 skipped, 0 failed')
        ->assertSuccessful();

    expect($probeCalls)->toBe(1);
});

it('continues after unsafe files, commits successes, and succeeds on a corrected rerun', function () {
    Process::fake(fn (PendingProcess $process) => Process::result(output: dynamicRangeProbeJson()));
    $good = createBackfillMediaFile($this->dynamicRangeDisk, 'Good/movie.mkv', [
        'index' => 0,
        'codec' => 'hevc',
        'width' => 3840,
        'height' => 1600,
    ]);
    $wrongSize = createBackfillMediaFile($this->dynamicRangeDisk, 'Wrong/movie.mkv', [
        'index' => 0,
        'codec' => 'hevc',
        'width' => 3840,
        'height' => 1600,
    ]);
    file_put_contents($this->dynamicRangeDisk.'/Wrong/movie.mkv', 'wrong-size');

    $this->artisan('media:metadata:backfill-dynamic-range')
        ->expectsOutputToContain('1 enriched, 0 skipped, 1 failed')
        ->assertFailed();

    expect($good->refresh()->video_metadata[0]['dynamic_range'])->toBe('hdr10_plus')
        ->and($wrongSize->refresh()->video_metadata[0])->not->toHaveKey('dynamic_range');

    file_put_contents($this->dynamicRangeDisk.'/Wrong/movie.mkv', 'movie-bytes');

    $this->artisan('media:metadata:backfill-dynamic-range')
        ->expectsOutputToContain('1 enriched, 1 skipped, 0 failed')
        ->assertSuccessful();

    expect($wrongSize->refresh()->video_metadata[0]['dynamic_range'])->toBe('hdr10_plus');
});

it('fails closed for unhealthy disks and guarded symlink paths without probing movie bytes', function (string $failure) {
    $probeCalls = 0;
    Process::fake(function (PendingProcess $process) use (&$probeCalls) {
        $probeCalls++;

        return Process::result(output: dynamicRangeProbeJson());
    });

    if ($failure === 'unhealthy') {
        createBackfillMediaFile($this->dynamicRangeDisk, 'Movie/movie.mkv', [
            'index' => 0,
            'codec' => 'hevc',
            'width' => 3840,
            'height' => 1600,
        ]);
        unlink($this->dynamicRangeDisk.'/.media-upload-manager/disk.json');
    } else {
        $outside = $this->dynamicRangeBase.'/outside.mkv';
        file_put_contents($outside, 'movie-bytes');
        createBackfillMediaFile($this->dynamicRangeDisk, 'Linked/movie.mkv', [
            'index' => 0,
            'codec' => 'hevc',
            'width' => 3840,
            'height' => 1600,
        ]);
        unlink($this->dynamicRangeDisk.'/Linked/movie.mkv');
        rmdir($this->dynamicRangeDisk.'/Linked');
        symlink($this->dynamicRangeBase, $this->dynamicRangeDisk.'/Linked');
    }

    $this->artisan('media:metadata:backfill-dynamic-range')
        ->expectsOutputToContain('0 enriched, 0 skipped, 1 failed')
        ->assertFailed();

    expect($probeCalls)->toBe(0);
})->with(['unhealthy', 'symlink']);
