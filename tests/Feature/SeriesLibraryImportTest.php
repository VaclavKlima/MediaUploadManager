<?php

use App\Actions\ReconcileMissingMediaFile;
use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Enums\UploadStatus;
use App\Jobs\ImportLibraryFinding;
use App\Jobs\ScanMediaLibrary;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\LibraryImportProcessor;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function configureSeriesLibraryImportDisk(
    string $root,
    ?SeriesCategory $seriesDefaultCategory = null,
): void {
    config()->set('media', [
        'disks' => [[
            'id' => 'series',
            'label' => 'Series',
            'path' => null,
            'movies_path' => null,
            'series_path' => $root,
            'series_default_category' => $seriesDefaultCategory?->value,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload.ffprobe_binary', 'ffprobe');
    config()->set('upload.ffprobe_timeout_seconds', '30');
    config()->set('upload.ffprobe_max_output_bytes', '1048576');
    config()->set('upload.ffprobe_max_streams', '64');
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array<string, mixed> */
function seriesLibraryImportDetails(): array
{
    return [
        'id' => 1396,
        'name' => 'Breaking Bad',
        'original_name' => 'Breaking Bad',
        'first_air_date' => '2008-01-20',
        'overview' => 'A chemistry teacher changes careers.',
        'poster_path' => '/breaking-bad.jpg',
        'original_language' => 'en',
        'number_of_episodes' => 62,
        'seasons' => [[
            'id' => 6001,
            'season_number' => 1,
            'name' => 'Season 1',
            'air_date' => '2008-01-20',
            'episode_count' => 7,
            'overview' => null,
            'poster_path' => null,
        ]],
    ];
}

/** @return array<string, mixed> */
function seriesLibraryImportSeason(): array
{
    return [
        'id' => 6001,
        'season_number' => 1,
        'name' => 'Season 1',
        'overview' => null,
        'poster_path' => null,
        'air_date' => '2008-01-20',
        'episodes' => [[
            'id' => 7102,
            'season_number' => 1,
            'episode_number' => 2,
            'name' => 'Cat\'s in the Bag...',
            'overview' => null,
            'air_date' => '2008-01-27',
            'runtime' => 48,
        ]],
    ];
}

function fakeSeriesLibraryImportTmdb(): void
{
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/external_ids')) {
            return Http::response(['imdb_id' => 'tt0903747', 'tvdb_id' => 81189]);
        }

        if (str_contains($request->url(), '/season/1')) {
            return Http::response(seriesLibraryImportSeason());
        }

        return Http::response(seriesLibraryImportDetails());
    });
}

function seriesLibraryImportProbe(): string
{
    return json_encode([
        'streams' => [[
            'index' => 0,
            'codec_name' => 'h264',
            'codec_type' => 'video',
            'width' => 1920,
            'height' => 1080,
            'disposition' => ['default' => 1],
        ]],
        'format' => ['format_name' => 'matroska', 'duration' => '48.5'],
    ], JSON_THROW_ON_ERROR);
}

function createSeriesLibraryFinding(string $root, User $administrator, array $overrides = []): LibraryFinding
{
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $sourceRelativePath = 'Incoming/Breaking.Bad.S01E02.mkv';
    $sourcePath = $root.'/'.$sourceRelativePath;
    (new Filesystem)->makeDirectory(dirname($sourcePath), 0750, true);
    file_put_contents($sourcePath, 'show-episode-bytes');
    $metadata = lstat($sourcePath);

    return LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'disk_id' => 'series',
        'relative_path' => $sourceRelativePath,
        'source_folder' => 'Incoming',
        'source_filename' => basename($sourceRelativePath),
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'ready',
        'identity_source' => 'manual',
        'identity_snapshot' => [
            'series' => ['tmdb_id' => 1396, 'name' => 'Breaking Bad'],
            'episode' => ['tmdb_id' => 7102, 'name' => 'Cat\'s in the Bag...'],
        ],
        'tmdb_id' => 1396,
        'series_category' => SeriesCategory::Tv,
        'season_number' => 1,
        'episode_number' => 2,
        'destination_relative_path' => "Breaking Bad (2008) [tmdbid-1396]/Season 01/Breaking Bad S01E02 - Cat's in the Bag/Breaking Bad S01E02 - Cat's in the Bag.mkv",
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->seriesImportFilesystem = new Filesystem;
    $this->seriesImportRoot = storage_path('framework/testing/series-library-import-'.bin2hex(random_bytes(6)));
    $this->seriesImportFilesystem->makeDirectory($this->seriesImportRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->seriesImportRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('series', MediaRootKind::Series),
    );
    configureSeriesLibraryImportDisk($this->seriesImportRoot);
    Cache::clear();
    fakeSeriesLibraryImportTmdb();
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result(output: seriesLibraryImportProbe()));
});

afterEach(function () {
    $this->seriesImportFilesystem->deleteDirectory($this->seriesImportRoot);
});

it('imports one selected Show episode and assigns immutable provenance and home disk', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator);

    app(LibraryImportProcessor::class)->process($finding, $administrator);
    app(LibraryImportProcessor::class)->process($finding->refresh(), $administrator);

    $series = Series::query()->sole();
    $season = SeriesSeason::query()->sole();
    $episode = SeriesEpisode::query()->sole();
    $mediaFile = MediaFile::query()->sole();
    $destination = $this->seriesImportRoot.'/'.$finding->destination_relative_path;

    expect($destination)->toBeFile()
        ->and($this->seriesImportRoot.'/'.$finding->relative_path)->not->toBeFile()
        ->and($series->home_disk_id)->toBe('series')
        ->and($series->last_episode_finalized_at?->equalTo($mediaFile->finalized_at))->toBeTrue()
        ->and($season->season_number)->toBe(1)
        ->and($episode->episode_number)->toBe(2)
        ->and($episode->current_media_file_id)->toBe($mediaFile->id)
        ->and($mediaFile->root_kind)->toBe(MediaRootKind::Series)
        ->and($mediaFile->source_upload_id)->toBeNull()
        ->and($mediaFile->imported_by_user_id)->toBe($administrator->id)
        ->and($mediaFile->import_provenance['type'])->toBe('recursive_series_library_import')
        ->and($finding->refresh()->series_episode_id)->toBe($episode->id)
        ->and($finding->resolution)->toBe('imported');
});

it('automatically imports normalized episodes from an opted-in Series root idempotently', function () {
    Queue::fake();
    configureSeriesLibraryImportDisk($this->seriesImportRoot, SeriesCategory::Tv);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/external_ids')) {
            return Http::response(['imdb_id' => 'tt0417299', 'tvdb_id' => 74852]);
        }

        if (str_contains($request->url(), '/season/1')) {
            return Http::response([
                'id' => 1001,
                'season_number' => 1,
                'name' => 'Book One: Water',
                'overview' => null,
                'poster_path' => null,
                'air_date' => '2005-02-21',
                'episodes' => [
                    [
                        'id' => 1018,
                        'season_number' => 1,
                        'episode_number' => 18,
                        'name' => 'The Waterbending Master',
                        'overview' => null,
                        'air_date' => '2005-11-18',
                        'runtime' => 24,
                    ],
                    [
                        'id' => 1019,
                        'season_number' => 1,
                        'episode_number' => 19,
                        'name' => 'The Siege of the North, Part 1',
                        'overview' => null,
                        'air_date' => '2005-12-02',
                        'runtime' => 24,
                    ],
                ],
            ]);
        }

        return Http::response([
            'id' => 246,
            'name' => 'Avatar: The Last Airbender',
            'original_name' => 'Avatar: The Last Airbender',
            'first_air_date' => '2005-02-21',
            'overview' => 'A young Avatar learns to master the elements.',
            'poster_path' => '/avatar.jpg',
            'original_language' => 'en',
            'number_of_episodes' => 61,
            'seasons' => [[
                'id' => 1001,
                'season_number' => 1,
                'name' => 'Book One: Water',
                'air_date' => '2005-02-21',
                'episode_count' => 20,
            ]],
        ]);
    });
    Process::fake(fn (PendingProcess $process) => Process::result(output: json_encode([
        'streams' => [[
            'index' => 0,
            'codec_name' => 'h264',
            'codec_type' => 'video',
            'width' => 1920,
            'height' => 1080,
            'disposition' => ['default' => 1],
        ]],
        'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '24.0'],
    ], JSON_THROW_ON_ERROR)));
    $incomingDirectory = $this->seriesImportRoot.'/Avatar - The Last Airbender [tmdbid-246]/Season 01';
    $this->seriesImportFilesystem->makeDirectory($incomingDirectory, 0750, true);
    file_put_contents($incomingDirectory.'/Avatar - The Last Airbender S01E18.mp4', 'episode-eighteen');
    file_put_contents($incomingDirectory.'/Avatar - The Last Airbender S01E19.mp4', 'episode-nineteen');
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'queued']);

    app()->call([new ScanMediaLibrary($scan->id), 'handle']);

    $findings = $scan->findings()->orderBy('episode_number')->get();
    $jobs = Queue::pushed(ImportLibraryFinding::class);
    expect($findings)->toHaveCount(2)
        ->and($findings->pluck('status')->unique()->sole())->toBe('import_queued')
        ->and($findings->pluck('series_category')->unique()->sole())->toBe(SeriesCategory::Tv)
        ->and($jobs)->toHaveCount(2);

    foreach ($jobs as $job) {
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
    }

    $destinationRoot = $this->seriesImportRoot.'/Avatar The Last Airbender (2005) [tmdbid-246]/Season 01';
    expect($destinationRoot.'/Avatar The Last Airbender S01E18 - The Waterbending Master/Avatar The Last Airbender S01E18 - The Waterbending Master.mp4')->toBeFile()
        ->and($destinationRoot.'/Avatar The Last Airbender S01E19 - The Siege of the North, Part 1/Avatar The Last Airbender S01E19 - The Siege of the North, Part 1.mp4')->toBeFile()
        ->and($incomingDirectory.'/Avatar - The Last Airbender S01E18.mp4')->not->toBeFile()
        ->and($incomingDirectory.'/Avatar - The Last Airbender S01E19.mp4')->not->toBeFile()
        ->and(Series::query()->sole()->category)->toBe(SeriesCategory::Tv)
        ->and(SeriesSeason::query()->count())->toBe(1)
        ->and(SeriesEpisode::query()->count())->toBe(2)
        ->and(MediaFile::query()->count())->toBe(2)
        ->and($findings->every(fn (LibraryFinding $finding): bool => $finding->refresh()->resolution === 'imported'))->toBeTrue();
});

it('rejects a Show finding when the catalogued home disk differs', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    Series::factory()->create([
        'tmdb_id' => 1396,
        'category' => SeriesCategory::Tv,
        'home_disk_id' => 'another-series-root',
    ]);
    $finding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator);

    expect(fn () => app(LibraryImportProcessor::class)->process($finding, $administrator))
        ->toThrow(RuntimeException::class, 'different Series root')
        ->and($this->seriesImportRoot.'/'.$finding->relative_path)->toBeFile()
        ->and(MediaFile::query()->count())->toBe(0);
});

it('uses an existing custom episode title for the canonical destination', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $series = Series::factory()->create([
        'tmdb_id' => 1396,
        'category' => SeriesCategory::Tv,
        'name' => 'Breaking Bad',
        'first_air_year' => 2008,
    ]);
    $season = SeriesSeason::factory()->for($series)->create([
        'tmdb_id' => 6001,
        'season_number' => 1,
        'name' => 'Season 1',
    ]);
    $episode = SeriesEpisode::factory()->for($season, 'season')->create([
        'tmdb_id' => 7102,
        'episode_number' => 2,
        'name' => 'Cat\'s in the Bag...',
        'custom_name' => 'The Bag',
    ]);
    $destination = 'Breaking Bad (2008) [tmdbid-1396]/Season 01/Breaking Bad S01E02 - The Bag/Breaking Bad S01E02 - The Bag.mkv';
    $finding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator, [
        'series_episode_id' => $episode->id,
        'destination_relative_path' => $destination,
    ]);

    app(LibraryImportProcessor::class)->process($finding, $administrator);

    expect($this->seriesImportRoot.'/'.$destination)->toBeFile()
        ->and($episode->refresh()->currentMediaFile?->relative_path)->toBe($destination)
        ->and($series->refresh()->home_disk_id)->toBe('series');
});

it('blocks a scan import when the selected Show episode has a current file', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $series = Series::factory()->create([
        'tmdb_id' => 1396,
        'category' => SeriesCategory::Tv,
        'name' => 'Breaking Bad',
        'first_air_year' => 2008,
    ]);
    $season = SeriesSeason::factory()->for($series)->create([
        'tmdb_id' => 6001,
        'season_number' => 1,
    ]);
    $episode = SeriesEpisode::factory()->for($season, 'season')->create([
        'tmdb_id' => 7102,
        'episode_number' => 2,
        'name' => 'Conflict',
    ]);
    $upload = Upload::factory()->for($administrator)->forSeriesEpisode($episode)->create([
        'disk_id' => 'series',
    ]);
    Upload::query()->whereKey($upload)->update(['status' => UploadStatus::Completed->value]);
    $upload->refresh();
    $current = MediaFile::factory()->forUpload($upload)->create();
    $episode->update(['current_media_file_id' => $current->id]);
    $finding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator, [
        'series_episode_id' => $episode->id,
        'destination_relative_path' => 'Breaking Bad (2008) [tmdbid-1396]/Season 01/Breaking Bad S01E02 - Conflict/Breaking Bad S01E02 - Conflict.mkv',
    ]);

    expect(fn () => app(LibraryImportProcessor::class)->process($finding, $administrator))
        ->toThrow(RuntimeException::class, 'current file or active upload')
        ->and($this->seriesImportRoot.'/'.$finding->relative_path)->toBeFile()
        ->and(MediaFile::query()->count())->toBe(1);
});

it('fails closed when a Show source changes after scanning', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator);
    file_put_contents($this->seriesImportRoot.'/'.$finding->relative_path, 'changed-show-episode-bytes');

    expect(fn () => app(LibraryImportProcessor::class)->process($finding, $administrator))
        ->toThrow(RuntimeException::class, 'scan snapshot')
        ->and(Series::query()->count())->toBe(0)
        ->and(MediaFile::query()->count())->toBe(0);
});

it('pairs and restores an inode-proven Show episode move', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $importFinding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator);
    app(LibraryImportProcessor::class)->process($importFinding, $administrator);
    $episode = SeriesEpisode::query()->sole();
    $oldMediaFile = MediaFile::query()->sole();
    $oldPath = $this->seriesImportRoot.'/'.$oldMediaFile->relative_path;
    $foundRelativePath = 'Moved/Breaking Bad [tmdbid-1396] S01E02.mkv';
    $foundPath = $this->seriesImportRoot.'/'.$foundRelativePath;
    $this->seriesImportFilesystem->makeDirectory(dirname($foundPath), 0750, true);
    rename($oldPath, $foundPath);
    $scan = LibraryScan::factory()->for($administrator)->create(['status' => 'queued']);

    app()->call([new ScanMediaLibrary($scan->id), 'handle']);

    $discovered = $scan->findings()->where('kind', 'discovered')->sole();
    $missing = $scan->findings()->where('kind', 'missing')->sole();
    expect($discovered->status)->toBe('restore_ready')
        ->and($discovered->paired_missing_finding_id)->toBe($missing->id)
        ->and($discovered->series_episode_id)->toBe($episode->id);

    app(LibraryImportProcessor::class)->process($discovered, $administrator);

    $newMediaFile = $episode->refresh()->currentMediaFile()->sole();
    expect($foundPath)->not->toBeFile()
        ->and($this->seriesImportRoot.'/'.$oldMediaFile->relative_path)->toBeFile()
        ->and($newMediaFile->id)->not->toBe($oldMediaFile->id)
        ->and($newMediaFile->root_kind)->toBe(MediaRootKind::Series)
        ->and($newMediaFile->import_provenance['previous_media_file_id'])->toBe($oldMediaFile->id)
        ->and($oldMediaFile->refresh()->removal_reason)->toBe('relocated')
        ->and($discovered->refresh()->resolution)->toBe('relocated')
        ->and($missing->refresh()->resolution)->toBe('relocated');
});

it('releases an externally missing Show path while retaining its catalog graph', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $finding = createSeriesLibraryFinding($this->seriesImportRoot, $administrator);
    app(LibraryImportProcessor::class)->process($finding, $administrator);
    $finding->refresh();
    $series = Series::query()->sole();
    $season = SeriesSeason::query()->sole();
    $episode = SeriesEpisode::query()->sole();
    $mediaFile = MediaFile::query()->sole();
    unlink($this->seriesImportRoot.'/'.$mediaFile->relative_path);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $missing = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'root_kind' => MediaRootKind::Series,
        'series_episode_id' => $episode->id,
        'media_file_id' => $mediaFile->id,
        'disk_id' => $mediaFile->disk_id,
        'relative_path' => $mediaFile->relative_path,
        'source_folder' => dirname($mediaFile->relative_path),
        'source_filename' => basename($mediaFile->relative_path),
        'size_bytes' => $mediaFile->size_bytes,
        'kind' => 'missing',
        'status' => 'missing',
    ]);

    app(ReconcileMissingMediaFile::class)->execute($missing, $administrator, true);

    expect($episode->refresh()->current_media_file_id)->toBeNull()
        ->and($mediaFile->refresh()->active_path_key)->toBeNull()
        ->and($mediaFile->removal_reason)->toBe('external_missing')
        ->and($series->refresh()->last_episode_finalized_at)->toBeNull()
        ->and(Series::query()->whereKey($series)->exists())->toBeTrue()
        ->and(SeriesSeason::query()->whereKey($season)->exists())->toBeTrue()
        ->and(SeriesEpisode::query()->whereKey($episode)->exists())->toBeTrue()
        ->and($missing->refresh()->resolution)->toBe('external_missing');
});
