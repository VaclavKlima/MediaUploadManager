<?php

use App\Actions\DeleteLibraryFinding;
use App\Actions\DeleteTrackedMovie;
use App\Jobs\DeleteDiscoveredLibraryFile;
use App\Jobs\ImportLibraryFinding;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\DiskMarker;
use App\Support\Media\Exceptions\HardLinkCreationException;
use App\Support\Media\LibraryImportProcessor;
use App\Support\Media\NativeMediaFilesystem;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

final class ToggleablePermissionMediaFilesystem extends NativeMediaFilesystem
{
    public bool $denyHardLinks = true;

    public function createHardLinkExclusively(string $source, string $target): bool
    {
        if ($this->denyHardLinks) {
            throw HardLinkCreationException::permissionDenied();
        }

        return parent::createHardLinkExclusively($source, $target);
    }
}

function configureLibraryImportDisk(string $root): void
{
    config()->set('media', [
        'disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => $root, 'reserve_gib' => '0']],
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

function libraryImportProbe(): string
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
        'format' => ['format_name' => 'matroska', 'duration' => '120.5'],
    ], JSON_THROW_ON_ERROR);
}

function libraryImportSnapshot(): array
{
    return [
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'title' => 'The Matrix',
        'original_title' => 'The Matrix',
        'release_date' => '1999-03-30',
        'release_year' => 1999,
        'overview' => null,
        'poster_path' => null,
        'original_language' => 'en',
        'metadata_version' => 1,
        'metadata_snapshot' => ['tmdb_id' => 603, 'title' => 'The Matrix'],
    ];
}

beforeEach(function () {
    $this->importFilesystem = new Filesystem;
    $this->importRoot = storage_path('framework/testing/library-import-'.bin2hex(random_bytes(6)));
    $this->importFilesystem->makeDirectory($this->importRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->importRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
    configureLibraryImportDisk($this->importRoot);
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result(output: libraryImportProbe()));
});

afterEach(function () {
    $this->importFilesystem->deleteDirectory($this->importRoot);
});

it('claims, hard-links, unlinks, and indexes a discovered movie without a synthetic upload', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $sourceRelativePath = 'loose/Wrong name.mkv';
    $sourcePath = $this->importRoot.'/'.$sourceRelativePath;
    $this->importFilesystem->makeDirectory(dirname($sourcePath), 0750, true);
    file_put_contents($sourcePath, 'movie-bytes');
    file_put_contents($this->importRoot.'/loose/.DS_Store', 'metadata');
    $metadata = lstat($sourcePath);
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => $sourceRelativePath,
        'source_folder' => 'loose',
        'source_filename' => 'Wrong name.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'ready',
        'identity_source' => 'manual',
        'identity_snapshot' => libraryImportSnapshot(),
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'destination_relative_path' => 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv',
    ]);

    app(LibraryImportProcessor::class)->process($finding, $administrator);
    app(LibraryImportProcessor::class)->process($finding->refresh(), $administrator);

    $destination = $this->importRoot.'/The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv';
    $mediaFile = MediaFile::query()->sole();

    expect($sourcePath)->not->toBeFile()
        ->and($this->importRoot.'/loose')->not->toBeDirectory()
        ->and($destination)->toBeFile()
        ->and(lstat($destination)['ino'])->toBe($metadata['ino'])
        ->and($mediaFile->source_upload_id)->toBeNull()
        ->and($mediaFile->imported_by_user_id)->toBe($administrator->id)
        ->and($mediaFile->import_provenance['library_finding_id'])->toBe($finding->id)
        ->and($finding->refresh()->resolution)->toBe('imported')
        ->and(FolderCleanup::query()->sole()->status)->toBe('completed')
        ->and(MediaItem::query()->sole()->current_media_file_id)->toBe($mediaFile->id);
});

it('retains a denied import claim and source inode then succeeds after permission restoration', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $sourceRelativePath = 'denied/Legacy movie.mkv';
    $sourcePath = $this->importRoot.'/'.$sourceRelativePath;
    $this->importFilesystem->makeDirectory(dirname($sourcePath), 0750, true);
    file_put_contents($sourcePath, 'legacy-movie-bytes');
    $metadata = lstat($sourcePath);
    $destinationRelativePath = 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv';
    $destinationPath = $this->importRoot.'/'.$destinationRelativePath;
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => $sourceRelativePath,
        'source_folder' => 'denied',
        'source_filename' => 'Legacy movie.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'ready',
        'identity_source' => 'manual',
        'identity_snapshot' => libraryImportSnapshot(),
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'destination_relative_path' => $destinationRelativePath,
    ]);
    $filesystem = new ToggleablePermissionMediaFilesystem;
    app()->instance(MediaFilesystem::class, $filesystem);
    $job = new ImportLibraryFinding($finding->id, $administrator->id);
    $failure = null;

    try {
        $job->handle(app(LibraryImportProcessor::class));
    } catch (Throwable $exception) {
        $failure = $exception;
        $job->failed($exception);
    }

    $actionableMessage = "Hard-link creation was denied by the media filesystem. Set MEDIA_GID to the source file's numeric group ID, recreate the media services, and retry the import.";
    $failedFinding = $finding->refresh();

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toBe($actionableMessage)
        ->and($failedFinding->status)->toBe('failed')
        ->and($failedFinding->error_detail)->toBe($actionableMessage)
        ->and($failedFinding->operation_claim['inode_id'])->toBe($metadata['ino'])
        ->and(lstat($sourcePath)['ino'])->toBe($metadata['ino'])
        ->and($destinationPath)->not->toBeFile()
        ->and(MediaFile::query()->count())->toBe(0);

    $filesystem->denyHardLinks = false;
    $job->handle(app(LibraryImportProcessor::class));

    expect($sourcePath)->not->toBeFile()
        ->and($destinationPath)->toBeFile()
        ->and(lstat($destinationPath)['ino'])->toBe($metadata['ino'])
        ->and($finding->refresh()->resolution)->toBe('imported')
        ->and(MediaFile::query()->sole()->import_provenance['relocation_proof']['inode_id'])->toBe($metadata['ino']);
});

it('fails closed when the source snapshot becomes stale', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $path = $this->importRoot.'/stale.mkv';
    file_put_contents($path, 'old');
    $metadata = lstat($path);
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => 'stale.mkv',
        'source_folder' => '',
        'source_filename' => 'stale.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'ready',
        'identity_snapshot' => libraryImportSnapshot(),
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'destination_relative_path' => 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv',
    ]);
    file_put_contents($path, 'changed');

    expect(fn () => app(LibraryImportProcessor::class)->process($finding, $administrator))
        ->toThrow(RuntimeException::class, 'scan snapshot')
        ->and(MediaItem::query()->count())->toBe(0)
        ->and(MediaFile::query()->count())->toBe(0);
});

it('deletes only an exact untracked finding through a durable claim without creating a movie', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $path = $this->importRoot.'/delete/me.mp4';
    $this->importFilesystem->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, 'delete-me');
    file_put_contents($this->importRoot.'/delete/movie.nfo', 'metadata');
    $metadata = lstat($path);
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => 'delete/me.mp4',
        'source_folder' => 'delete',
        'source_filename' => 'me.mp4',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'needs_identification',
    ]);

    app(DeleteLibraryFinding::class)->confirm($finding, $administrator, true);
    Queue::assertPushed(DeleteDiscoveredLibraryFile::class);
    app(DeleteLibraryFinding::class)->process($finding->refresh(), $administrator);

    expect($path)->not->toBeFile()
        ->and($this->importRoot.'/delete')->not->toBeDirectory()
        ->and($finding->refresh()->resolution)->toBe('deleted')
        ->and($finding->operation_claim['type'])->toBe('delete')
        ->and(FolderCleanup::query()->sole()->status)->toBe('completed')
        ->and(MediaItem::query()->count())->toBe(0);
});

it('keeps a shared source folder until its final discovered video is deleted', function () {
    Queue::fake();
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $this->importFilesystem->makeDirectory($this->importRoot.'/shared', 0750, true);
    file_put_contents($this->importRoot.'/shared/first.mkv', 'first');
    file_put_contents($this->importRoot.'/shared/second.mp4', 'second');
    file_put_contents($this->importRoot.'/shared/poster.jpg', 'art');

    $findings = collect(['first.mkv', 'second.mp4'])->map(function (string $filename) use ($scan): LibraryFinding {
        $path = $this->importRoot.'/shared/'.$filename;
        $metadata = lstat($path);

        return LibraryFinding::query()->create([
            'library_scan_id' => $scan->id,
            'disk_id' => 'movies',
            'relative_path' => 'shared/'.$filename,
            'source_folder' => 'shared',
            'source_filename' => $filename,
            'size_bytes' => $metadata['size'],
            'device_id' => $metadata['dev'],
            'inode_id' => $metadata['ino'],
            'kind' => 'discovered',
            'status' => 'needs_identification',
        ]);
    });

    foreach ($findings as $index => $finding) {
        app(DeleteLibraryFinding::class)->confirm($finding, $administrator, true);
        app(DeleteLibraryFinding::class)->process($finding->refresh(), $administrator);

        if ($index === 0) {
            expect($this->importRoot.'/shared')->toBeDirectory();
        } else {
            expect($this->importRoot.'/shared')->not->toBeDirectory();
        }
    }

    expect(FolderCleanup::query()->count())->toBe(1)
        ->and(FolderCleanup::query()->sole()->status)->toBe('completed');
});

it('allows an administrator to safely delete an imported tracked movie', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $administrator->id, 'status' => 'completed']);
    $sourcePath = $this->importRoot.'/imported.mkv';
    file_put_contents($sourcePath, 'movie-bytes');
    $metadata = lstat($sourcePath);
    $finding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => 'imported.mkv',
        'source_folder' => '',
        'source_filename' => 'imported.mkv',
        'size_bytes' => $metadata['size'],
        'device_id' => $metadata['dev'],
        'inode_id' => $metadata['ino'],
        'kind' => 'discovered',
        'status' => 'ready',
        'identity_snapshot' => libraryImportSnapshot(),
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'destination_relative_path' => 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv',
    ]);
    app(LibraryImportProcessor::class)->process($finding, $administrator);
    $movie = MediaItem::query()->sole();
    $canonicalPath = $this->importRoot.'/'.$movie->currentMediaFile()->sole()->relative_path;

    app(DeleteTrackedMovie::class)->execute($movie, $administrator, true);

    expect($canonicalPath)->not->toBeFile()
        ->and(MediaItem::query()->count())->toBe(0)
        ->and(MediaFile::query()->count())->toBe(0)
        ->and($finding->refresh()->media_item_id)->toBeNull()
        ->and($finding->media_file_id)->toBeNull();
});
