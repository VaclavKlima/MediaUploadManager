<?php

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\MovieUploadConflictChecker;
use App\Support\Media\TrackedMovieDeletionClaim;
use Illuminate\Filesystem\Filesystem;

function configureMovieConflictDisks(array $disks): void
{
    config()->set('media', [
        'disks' => $disks,
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

function setConflictUploadStatus(Upload $upload, UploadStatus $status): Upload
{
    Upload::query()->whereKey($upload)->update(['status' => $status->value]);

    return $upload->refresh();
}

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->conflictBase = storage_path('framework/testing/movie-conflict-'.bin2hex(random_bytes(6)));
    $this->diskA = $this->conflictBase.'/a';
    $this->diskB = $this->conflictBase.'/b';
    $this->diskC = $this->conflictBase.'/c';
    $this->filesystem->makeDirectory($this->diskA, 0750, true);
    $this->filesystem->makeDirectory($this->diskB, 0750, true);
    $this->filesystem->makeDirectory($this->diskC, 0750, true);
    $this->filesystem->makeDirectory($this->diskA.'/.media-upload-manager', 0750);
    $this->filesystem->makeDirectory($this->diskB.'/.media-upload-manager', 0750);
    $this->filesystem->makeDirectory($this->diskC.'/.media-upload-manager', 0750);
    file_put_contents($this->diskA.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_a'));
    file_put_contents($this->diskB.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_b'));
    file_put_contents($this->diskC.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_c'));

    configureMovieConflictDisks([
        ['id' => 'movies_b', 'label' => 'Movies B', 'path' => $this->diskB],
        ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $this->diskA],
    ]);

    $this->mediaItem = MediaItem::factory()->create([
        'title' => 'The Matrix',
        'release_year' => 1999,
        'tmdb_id' => 603,
    ]);
    $this->canonicalPath = (new JellyfinMoviePathBuilder)->build($this->mediaItem, 'matrix.MKV');
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->conflictBase);
});

it('reports multiple clear disks in deterministic ID order', function () {
    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath);

    expect($report->canStartNewUpload)->toBeTrue()
        ->and($report->blockers)->toBe([])
        ->and(array_column($report->toArray()['disks'], 'id'))->toBe(['movies_a', 'movies_b'])
        ->and(array_column($report->toArray()['disks'], 'status'))->toBe(['clear', 'clear']);
});

it('blocks new uploads while a durable movie deletion claim exists', function () {
    $claim = TrackedMovieDeletionClaim::forOrphan(
        $this->mediaItem->getKey(),
        123,
        $this->mediaItem->title,
    );
    $this->mediaItem->update([
        'deletion_claim' => $claim->toArray(),
        'deletion_requested_at' => now(),
    ]);

    $report = app(MovieUploadConflictChecker::class)->check(
        $this->mediaItem->refresh(),
        $this->canonicalPath,
    );

    expect($report->canStartNewUpload)->toBeFalse()
        ->and(collect($report->toArray()['blockers'])->pluck('code'))
        ->toContain('movie_deletion_in_progress');
});

it('allows administrators to replace an imported current primary', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $directory = $this->diskA.'/'.$this->canonicalPath->directory;
    $path = $this->diskA.'/'.$this->canonicalPath->relativePath;
    $this->filesystem->makeDirectory($directory, 0750, true);
    file_put_contents($path, 'imported movie');
    $mediaFile = MediaFile::query()->create([
        'media_item_id' => $this->mediaItem->id,
        'source_upload_id' => null,
        'imported_by_user_id' => $administrator->id,
        'import_provenance' => ['type' => 'recursive_library_import', 'library_finding_id' => 123],
        'disk_id' => 'movies_a',
        'relative_path' => $this->canonicalPath->relativePath,
        'size_bytes' => strlen('imported movie'),
        'container' => 'matroska',
        'duration_milliseconds' => 120_000,
        'video_metadata' => [['codec' => 'h264']],
        'audio_metadata' => [],
        'probe_snapshot' => ['format' => ['container' => 'matroska']],
        'finalized_at' => now(),
    ]);
    $this->mediaItem->update(['current_media_file_id' => $mediaFile->id]);

    $administratorReport = app(MovieUploadConflictChecker::class)->check(
        $this->mediaItem->refresh(),
        $this->canonicalPath,
        $administrator,
    );
    $ordinaryUserReport = app(MovieUploadConflictChecker::class)->check(
        $this->mediaItem,
        $this->canonicalPath,
        User::factory()->create(),
    );

    expect($administratorReport->canReplaceCurrentPrimary)->toBeTrue()
        ->and($administratorReport->replaceable?->sourceUploadId)->toBeNull()
        ->and($ordinaryUserReport->canReplaceCurrentPrimary)->toBeFalse();
});

it('blocks globally when the current primary is on another disk', function () {
    $upload = Upload::factory()->for($this->mediaItem)->create(['disk_id' => 'movies_b']);
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();
    $this->mediaItem->update(['current_media_file_id' => $mediaFile->id]);

    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem->refresh(), $this->canonicalPath);
    $blockers = collect($report->toArray()['blockers']);

    expect($report->canStartNewUpload)->toBeFalse()
        ->and($blockers->pluck('code'))->toContain('current_primary_exists')
        ->and($blockers->firstWhere('code', 'current_primary_exists')['disk'])->toBe([
            'id' => 'movies_b',
            'label' => 'Movies B',
        ])
        ->and(collect($report->toArray()['disks'])->firstWhere('id', 'movies_b')['status'])->toBe('conflict')
        ->and(collect($report->toArray()['disks'])->firstWhere('id', 'movies_a')['status'])->toBe('clear');
});

it('blocks live and retryable uploads on another disk', function (UploadStatus $status, string $code) {
    $upload = Upload::factory()->for($this->mediaItem)->create(['disk_id' => 'movies_b']);
    setConflictUploadStatus($upload, $status);

    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath);

    expect($report->canStartNewUpload)->toBeFalse()
        ->and(collect($report->toArray()['blockers'])->pluck('code'))->toContain($code)
        ->and(collect($report->toArray()['disks'])->firstWhere('id', 'movies_b')['status'])->toBe('conflict');
})->with([
    'pending' => [UploadStatus::Pending, 'active_upload_exists'],
    'uploading' => [UploadStatus::Uploading, 'active_upload_exists'],
    'paused' => [UploadStatus::Paused, 'active_upload_exists'],
    'processing' => [UploadStatus::Processing, 'active_upload_exists'],
    'failed retryable' => [UploadStatus::Failed, 'retryable_upload_exists'],
    'completed' => [UploadStatus::Completed, 'completed_upload_exists'],
]);

it('recovers a live database media file without a current-primary relation', function () {
    $upload = Upload::factory()->for($this->mediaItem)->create(['disk_id' => 'movies_b']);
    MediaFile::factory()->forUpload($upload)->create();
    setConflictUploadStatus($upload, UploadStatus::Cancelled);

    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath);

    expect($report->canStartNewUpload)->toBeFalse()
        ->and(collect($report->toArray()['blockers'])->pluck('code'))->toContain('media_file_exists');
});

it('blocks matching canonical directories and files on another disk without database rows', function () {
    $directory = $this->diskB.'/'.$this->canonicalPath->directory;
    $this->filesystem->makeDirectory($directory, 0750, true);
    file_put_contents($this->diskB.'/'.$this->canonicalPath->relativePath, 'existing movie');

    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath);

    expect($report->canStartNewUpload)->toBeFalse()
        ->and(collect($report->toArray()['blockers'])->pluck('code'))
        ->toContain('target_directory_exists', 'target_file_exists')
        ->and(collect($report->toArray()['disks'])->firstWhere('id', 'movies_b')['status'])->toBe('conflict');
});

it('ignores cancelled and expired uploads', function (UploadStatus $status) {
    $upload = Upload::factory()->for($this->mediaItem)->create(['disk_id' => 'movies_b']);
    setConflictUploadStatus($upload, $status);

    expect(app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath)->canStartNewUpload)
        ->toBeTrue();
})->with([UploadStatus::Cancelled, UploadStatus::Expired]);

it('ignores replaced and removed historical media rows', function (string $historicalColumn) {
    $upload = Upload::factory()->for($this->mediaItem)->create(['disk_id' => 'movies_b']);
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();
    setConflictUploadStatus($upload, UploadStatus::Cancelled);
    MediaFile::query()->whereKey($mediaFile)->update([$historicalColumn => now()]);

    expect(app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath)->canStartNewUpload)
        ->toBeTrue();
})->with(['replaced_at', 'removed_at']);

it('reports mixed clear conflicting and unavailable disk targets', function () {
    configureMovieConflictDisks([
        ['id' => 'movies_c', 'label' => 'Movies C', 'path' => $this->diskC],
        ['id' => 'movies_b', 'label' => 'Movies B', 'path' => $this->diskB],
        ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $this->diskA],
    ]);
    $this->filesystem->deleteDirectory($this->diskC);
    $this->filesystem->makeDirectory($this->diskB.'/'.$this->canonicalPath->directory, 0750, true);

    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath);

    expect(array_column($report->toArray()['disks'], 'id'))->toBe(['movies_a', 'movies_b', 'movies_c'])
        ->and(array_column($report->toArray()['disks'], 'status'))->toBe(['clear', 'conflict', 'unavailable'])
        ->and($report->canStartNewUpload)->toBeFalse();
});

it('blocks when no configured disk can be checked safely', function () {
    $this->filesystem->deleteDirectory($this->diskA);
    $this->filesystem->deleteDirectory($this->diskB);

    $report = app(MovieUploadConflictChecker::class)->check($this->mediaItem, $this->canonicalPath);

    expect($report->canStartNewUpload)->toBeFalse()
        ->and(collect($report->toArray()['blockers'])->pluck('code')->all())->toBe(['no_clear_disk'])
        ->and(array_column($report->toArray()['disks'], 'status'))->toBe(['unavailable', 'unavailable']);
});
