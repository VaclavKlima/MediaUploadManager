<?php

use App\Actions\PreviewFolderCleanup;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\FolderCleanupProcessor;
use Illuminate\Filesystem\Filesystem;

function configureLibraryCleanupDisk(string $root): void
{
    config()->set('media', [
        'disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => $root, 'reserve_gib' => '0']],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

beforeEach(function () {
    $this->cleanupFilesystem = new Filesystem;
    $this->cleanupRoot = storage_path('framework/testing/library-cleanup-'.bin2hex(random_bytes(6)));
    $this->cleanupFilesystem->makeDirectory($this->cleanupRoot.'/.media-upload-manager/incoming', 0750, true);
    $this->cleanupFilesystem->makeDirectory($this->cleanupRoot.'/old/nested', 0750, true);
    file_put_contents($this->cleanupRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
    configureLibraryCleanupDisk($this->cleanupRoot);
    $this->cleanupAdministrator = User::factory()->create(['is_administrator' => true]);
    $scan = LibraryScan::query()->create(['user_id' => $this->cleanupAdministrator->id, 'status' => 'completed']);
    $this->cleanupFinding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies',
        'relative_path' => 'old/movie.mkv',
        'source_folder' => 'old',
        'source_filename' => 'movie.mkv',
        'size_bytes' => 10,
        'device_id' => 1,
        'inode_id' => 2,
        'kind' => 'discovered',
        'status' => 'resolved',
        'resolution' => 'deleted',
        'resolved_at' => now(),
    ]);
});

afterEach(function () {
    $this->cleanupFilesystem->deleteDirectory($this->cleanupRoot);
});

it('persists a complete residue manifest and deletes only confirmed regular files', function () {
    file_put_contents($this->cleanupRoot.'/old/movie.nfo', 'metadata');
    file_put_contents($this->cleanupRoot.'/old/poster.jpg', 'art');
    file_put_contents($this->cleanupRoot.'/old/nested/subtitle.srt', 'subtitle');
    $cleanup = app(PreviewFolderCleanup::class)->execute($this->cleanupFinding, $this->cleanupAdministrator);

    expect($cleanup->file_count)->toBe(3)
        ->and($cleanup->total_size_bytes)->toBe(strlen('metadataartsubtitle'))
        ->and($cleanup->library_finding_id)->toBe($this->cleanupFinding->id)
        ->and(collect($cleanup->manifest)->pluck('relative_path')->all())->toContain(
            'old',
            'old/movie.nfo',
            'old/poster.jpg',
            'old/nested',
            'old/nested/subtitle.srt',
        );

    $cleanup->update(['status' => 'deleting', 'confirmed_at' => now()]);
    app(FolderCleanupProcessor::class)->process($cleanup, $this->cleanupAdministrator);

    expect($this->cleanupRoot.'/old')->not->toBeDirectory()
        ->and($cleanup->refresh()->status)->toBe('completed');
});

it('includes and removes empty ancestors without ever including the disk root', function () {
    $this->cleanupFilesystem->makeDirectory($this->cleanupRoot.'/abandoned/leaf', 0750, true);
    $this->cleanupFinding->update([
        'relative_path' => 'abandoned/leaf/movie.mkv',
        'source_folder' => 'abandoned/leaf',
    ]);
    $cleanup = app(PreviewFolderCleanup::class)->execute($this->cleanupFinding->refresh(), $this->cleanupAdministrator);

    expect(collect($cleanup->manifest)->pluck('relative_path')->all())
        ->toBe(['abandoned', 'abandoned/leaf'])
        ->and(collect($cleanup->manifest)->pluck('relative_path'))->not->toContain('')
        ->and($cleanup->relative_folder)->toBe('abandoned');

    $cleanup->update(['status' => 'deleting', 'confirmed_at' => now()]);
    app(FolderCleanupProcessor::class)->process($cleanup, $this->cleanupAdministrator);

    expect($this->cleanupRoot.'/abandoned/leaf')->not->toBeDirectory()
        ->and($this->cleanupRoot.'/abandoned')->not->toBeDirectory()
        ->and($this->cleanupRoot)->toBeDirectory();
});

it('includes non-video residue from removable ancestors in the confirmed manifest', function () {
    $this->cleanupFilesystem->makeDirectory($this->cleanupRoot.'/abandoned/leaf', 0750, true);
    file_put_contents($this->cleanupRoot.'/abandoned/.DS_Store', 'metadata');
    $this->cleanupFinding->update([
        'relative_path' => 'abandoned/leaf/movie.mkv',
        'source_folder' => 'abandoned/leaf',
    ]);

    $cleanup = app(PreviewFolderCleanup::class)->execute($this->cleanupFinding->refresh(), $this->cleanupAdministrator);

    expect($cleanup->relative_folder)->toBe('abandoned')
        ->and($cleanup->file_count)->toBe(1)
        ->and(collect($cleanup->manifest)->pluck('relative_path')->all())->toBe([
            'abandoned',
            'abandoned/.DS_Store',
            'abandoned/leaf',
        ]);
});

it('previews the nearest existing ancestor and all of its residue after a legacy leaf cleanup', function () {
    $this->cleanupFilesystem->makeDirectory($this->cleanupRoot.'/legacy/leaf', 0750, true);
    file_put_contents($this->cleanupRoot.'/legacy/.DS_Store', 'metadata');
    $this->cleanupFinding->update([
        'relative_path' => 'legacy/leaf/movie.mkv',
        'source_folder' => 'legacy/leaf',
    ]);
    $this->cleanupFilesystem->deleteDirectory($this->cleanupRoot.'/legacy/leaf');

    $cleanup = app(PreviewFolderCleanup::class)->execute($this->cleanupFinding->refresh(), $this->cleanupAdministrator);

    expect($cleanup->relative_folder)->toBe('legacy')
        ->and($cleanup->file_count)->toBe(1)
        ->and(collect($cleanup->manifest)->pluck('relative_path')->all())->toBe(['legacy', 'legacy/.DS_Store']);

    $cleanup->update(['status' => 'deleting', 'confirmed_at' => now()]);
    app(FolderCleanupProcessor::class)->process($cleanup, $this->cleanupAdministrator);

    expect($this->cleanupRoot.'/legacy')->not->toBeDirectory()
        ->and($cleanup->refresh()->status)->toBe('completed');
});

it('does not include an ancestor that contains another supported video', function () {
    $this->cleanupFilesystem->makeDirectory($this->cleanupRoot.'/shared/leaf', 0750, true);
    file_put_contents($this->cleanupRoot.'/shared/keep.mkv', 'movie');
    $this->cleanupFinding->update([
        'relative_path' => 'shared/leaf/movie.mkv',
        'source_folder' => 'shared/leaf',
    ]);

    $cleanup = app(PreviewFolderCleanup::class)->execute($this->cleanupFinding->refresh(), $this->cleanupAdministrator);

    expect(collect($cleanup->manifest)->pluck('relative_path')->all())
        ->toBe(['shared/leaf']);
});

it('retains files added after confirmation and reports partial cleanup', function () {
    file_put_contents($this->cleanupRoot.'/old/movie.nfo', 'metadata');
    $cleanup = app(PreviewFolderCleanup::class)->execute($this->cleanupFinding, $this->cleanupAdministrator);
    $cleanup->update(['status' => 'deleting', 'confirmed_at' => now()]);
    file_put_contents($this->cleanupRoot.'/old/new-file.txt', 'keep');

    app(FolderCleanupProcessor::class)->process($cleanup, $this->cleanupAdministrator);

    expect($this->cleanupRoot.'/old/movie.nfo')->not->toBeFile()
        ->and($this->cleanupRoot.'/old/new-file.txt')->toBeFile()
        ->and($cleanup->refresh()->status)->toBe('partial');
});

it('rejects cleanup for remaining videos, symlinks, and the disk root', function (string $unsafe): void {
    if ($unsafe === 'video') {
        file_put_contents($this->cleanupRoot.'/old/another.mp4', 'movie');
    } elseif ($unsafe === 'symlink') {
        symlink($this->cleanupRoot.'/old/nested', $this->cleanupRoot.'/old/link');
    } else {
        $this->cleanupFinding->update(['source_folder' => '']);
    }

    expect(fn () => app(PreviewFolderCleanup::class)->execute($this->cleanupFinding->refresh(), $this->cleanupAdministrator))
        ->toThrow(RuntimeException::class);
})->with(['video', 'symlink', 'root']);
