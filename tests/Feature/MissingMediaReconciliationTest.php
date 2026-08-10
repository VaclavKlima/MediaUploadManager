<?php

use App\Actions\ReconcileMissingMediaFile;
use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use Illuminate\Filesystem\Filesystem;

function configureMissingReconciliationDisk(string $root): void
{
    config()->set('media', [
        'disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => $root, 'reserve_gib' => '0']],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

beforeEach(function () {
    $this->missingFilesystem = new Filesystem;
    $this->missingRoot = storage_path('framework/testing/missing-reconcile-'.bin2hex(random_bytes(6)));
    $this->missingFilesystem->makeDirectory($this->missingRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->missingRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
    configureMissingReconciliationDisk($this->missingRoot);
    $this->missingAdministrator = User::factory()->create(['is_administrator' => true]);
    $this->missingMovie = MediaItem::factory()->create();
    $upload = Upload::factory()->for($this->missingMovie)->for($this->missingAdministrator)->create(['declared_size' => 10]);
    Upload::query()->whereKey($upload)->update(['status' => UploadStatus::Completed->value]);
    $this->missingMediaFile = MediaFile::factory()->forUpload($upload->refresh())->create(['disk_id' => 'movies']);
    $this->missingMovie->update(['current_media_file_id' => $this->missingMediaFile->id]);
    $scan = LibraryScan::query()->create(['user_id' => $this->missingAdministrator->id, 'status' => 'completed']);
    $this->missingFinding = LibraryFinding::query()->create([
        'library_scan_id' => $scan->id,
        'media_item_id' => $this->missingMovie->id,
        'media_file_id' => $this->missingMediaFile->id,
        'disk_id' => 'movies',
        'relative_path' => $this->missingMediaFile->relative_path,
        'source_folder' => dirname($this->missingMediaFile->relative_path),
        'source_filename' => basename($this->missingMediaFile->relative_path),
        'size_bytes' => $this->missingMediaFile->size_bytes,
        'kind' => 'missing',
        'status' => 'missing',
    ]);
});

afterEach(function () {
    $this->missingFilesystem->deleteDirectory($this->missingRoot);
});

it('releases the active path while preserving movie, upload, and technical history', function () {
    app(ReconcileMissingMediaFile::class)->execute($this->missingFinding, $this->missingAdministrator, true);

    expect($this->missingMovie->refresh()->current_media_file_id)->toBeNull()
        ->and($this->missingMediaFile->refresh()->removal_reason)->toBe('external_missing')
        ->and($this->missingMediaFile->active_path_key)->toBeNull()
        ->and($this->missingMediaFile->sourceUpload()->exists())->toBeTrue()
        ->and($this->missingFinding->refresh()->resolution)->toBe('external_missing');
});

it('refuses reconciliation and resolves the finding when the file returns', function () {
    $path = $this->missingRoot.'/'.$this->missingMediaFile->relative_path;
    $this->missingFilesystem->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, str_repeat('x', $this->missingMediaFile->size_bytes));

    expect(fn () => app(ReconcileMissingMediaFile::class)->execute($this->missingFinding, $this->missingAdministrator, true))
        ->toThrow(RuntimeException::class, 'returned')
        ->and($this->missingFinding->refresh()->resolution)->toBe('restored')
        ->and($this->missingMovie->refresh()->current_media_file_id)->toBe($this->missingMediaFile->id);
});
