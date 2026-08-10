<?php

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\TrackedMovieDeletionClaim;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

function configureMovieLibraryTestDisk(string $root, string $metadataPath): void
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
    config()->set('upload.tus_metadata_path', $metadataPath);

    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array{MediaItem, Upload, MediaFile, string} */
function createMovieLibraryPrimary(User $owner, string $root, string $title = 'Arrival'): array
{
    $mediaItem = MediaItem::factory()->create([
        'title' => $title,
        'original_title' => $title,
        'release_year' => 2016,
    ]);
    $relativePath = $title.' (2016) [tmdbid-'.$mediaItem->tmdb_id.']/'.$title.' (2016) [tmdbid-'.$mediaItem->tmdb_id.'].mkv';
    $contents = 'old-primary!';
    $upload = Upload::factory()->for($owner)->for($mediaItem)->create([
        'disk_id' => 'movies',
        'target_relative_path' => $relativePath,
        'declared_size' => strlen($contents),
    ]);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Completed->value,
        'confirmed_offset' => strlen($contents),
        'completed_at' => now(),
        'expires_at' => null,
    ]);
    $upload->refresh();
    $mediaFile = MediaFile::factory()->forUpload($upload)->create([
        'disk_id' => 'movies',
        'relative_path' => $relativePath,
        'size_bytes' => strlen($contents),
    ]);
    $mediaItem->update(['current_media_file_id' => $mediaFile->getKey()]);

    $path = $root.'/'.$relativePath;
    (new Filesystem)->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, $contents);

    return [$mediaItem->refresh(), $upload, $mediaFile, $path];
}

beforeEach(function () {
    $this->movieLibraryFilesystem = new Filesystem;
    $this->movieLibraryBase = storage_path('framework/testing/movie-library-'.bin2hex(random_bytes(6)));
    $this->movieLibraryDisk = $this->movieLibraryBase.'/movies';
    $this->movieLibraryMetadata = $this->movieLibraryBase.'/metadata';
    $this->movieLibraryFilesystem->makeDirectory(
        $this->movieLibraryDisk.'/.media-upload-manager/incoming',
        0750,
        true,
    );
    $this->movieLibraryFilesystem->makeDirectory($this->movieLibraryMetadata, 0750, true);
    file_put_contents(
        $this->movieLibraryDisk.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies'),
    );
    configureMovieLibraryTestDisk($this->movieLibraryDisk, $this->movieLibraryMetadata);
});

afterEach(function () {
    $this->movieLibraryFilesystem->deleteDirectory($this->movieLibraryBase);
});

it('requires authentication for movie management', function () {
    $this->get(route('movies.index'))->assertRedirect(route('login'));
    $this->delete(route('movies.destroy', MediaItem::factory()->create()))
        ->assertRedirect(route('login'));
});

it('lists tracked movies with search status sorting and deletion permissions', function () {
    $actor = User::factory()->create();
    [$arrival] = createMovieLibraryPrimary($actor, $this->movieLibraryDisk, 'Arrival');
    createMovieLibraryPrimary(User::factory()->create(), $this->movieLibraryDisk, 'Zodiac');
    MediaItem::factory()->create(['title' => 'Database Orphan']);

    $this->actingAs($actor)
        ->get(route('movies.index', [
            'search' => 'Arrival',
            'status' => 'available',
            'sort' => 'title',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('movies/Index')
            ->where('filters', [
                'search' => 'Arrival',
                'status' => 'available',
                'sort' => 'title',
            ])
            ->has('movies.data', 1)
            ->where('movies.per_page', 48)
            ->where('movies.data.0.id', $arrival->getKey())
            ->where('movies.data.0.title', 'Arrival')
            ->where('movies.data.0.state', 'available')
            ->where('movies.data.0.current_file.disk.label', 'Movies')
            ->where('movies.data.0.can_delete', true)
        );
});

it('renders a compact responsive library and irreversible deletion checkbox', function () {
    $page = file_get_contents(resource_path('js/pages/movies/Index.vue'));
    $card = file_get_contents(resource_path('js/components/movie-library/MovieLibraryCard.vue'));
    $dialog = file_get_contents(resource_path('js/components/movie-library/MovieDeleteDialog.vue'));
    $details = file_get_contents(resource_path('js/components/movie-library/MovieDetailsDrawer.vue'));
    $reidentification = file_get_contents(resource_path('js/components/movie-library/MovieReidentificationDialog.vue'));
    $identify = file_get_contents(resource_path('js/components/movie-upload/IdentifyMovieStep.vue'));

    expect($page)
        ->toContain('grid-cols-2')
        ->toContain('sm:grid-cols-4')
        ->toContain('lg:grid-cols-6')
        ->toContain('2xl:grid-cols-8')
        ->toContain('Search tracked movies')
        ->toContain("from '@/actions/App/Http/Controllers/MovieLibraryController'")
        ->and($card)
        ->toContain('Actions for')
        ->toContain('View details')
        ->toContain('Change identification')
        ->toContain('Delete movie')
        ->not->toContain('movie.current_file')
        ->and($details)
        ->toContain('movie.current_file.relative_path')
        ->toContain('Failed identification change')
        ->and($reidentification)
        ->toContain('MovieReidentificationController.preview.url')
        ->toContain('MovieReidentificationController.store.url')
        ->toContain('!preview.eligible')
        ->toContain('max-h-[calc(100dvh-2rem)]')
        ->toContain('overflow-y-auto')
        ->and($identify)
        ->toContain('const overviewCharacterLimit = 80')
        ->toContain('limitOverview(movie.overview)')
        ->toContain('line-clamp-2')
        ->not->toContain('line-clamp-3')
        ->and($dialog)
        ->toContain('movie.current_file.relative_path')
        ->toContain('deletion_confirmed')
        ->toContain('I understand that this permanently deletes')
        ->toContain(':disabled="!form.deletion_confirmed || form.processing"')
        ->toContain('destroy.url(props.movie.id)')
        ->toContain("onSuccess: () => emit('update:open', false)")
        ->toContain('exact tracked primary')
        ->toContain('related application records')
        ->toContain('operator-managed sidecars are not deleted');
});

it('lets the current primary owner permanently delete the database graph and exact file only', function () {
    Log::spy();
    $owner = User::factory()->create();
    [$mediaItem, $upload, $mediaFile, $path] = createMovieLibraryPrimary(
        $owner,
        $this->movieLibraryDisk,
    );
    $directory = dirname($path);
    file_put_contents($directory.'/movie.nfo', 'keep-nfo');
    file_put_contents($directory.'/poster.jpg', 'keep-poster');
    file_put_contents($directory.'/subtitle.srt', 'keep-subtitle');

    $this->actingAs($owner)
        ->from(route('movies.index'))
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertRedirect(route('movies.index'))
        ->assertSessionHasNoErrors();

    expect($path)->not->toBeFile()
        ->and($directory)->toBeDirectory()
        ->and(file_get_contents($directory.'/movie.nfo'))->toBe('keep-nfo')
        ->and(file_get_contents($directory.'/poster.jpg'))->toBe('keep-poster')
        ->and(file_get_contents($directory.'/subtitle.srt'))->toBe('keep-subtitle')
        ->and(MediaItem::query()->find($mediaItem->getKey()))->toBeNull()
        ->and(Upload::query()->find($upload->getKey()))->toBeNull()
        ->and(MediaFile::query()->find($mediaFile->getKey()))->toBeNull();

    Log::shouldHaveReceived('notice')->twice()->with('security.audit', Mockery::on(
        fn (array $context): bool => in_array($context['event'], [
            'movie_deletion_confirmed',
            'movie_deletion_completed',
        ], true)
            && $context['media_item_id'] === $mediaItem->getKey()
            && ! array_key_exists('relative_path', $context),
    ));
});

it('removes the obsolete movie directory only when it is empty', function () {
    $owner = User::factory()->create();
    [$mediaItem, , , $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);
    $directory = dirname($path);

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasNoErrors();

    expect($directory)->not->toBeDirectory();
});

it('allows administrators and denies users who do not own the primary', function () {
    $owner = User::factory()->create();
    [$mediaItem, , , $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);

    $this->actingAs(User::factory()->create())
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertForbidden();

    expect($path)->toBeFile()
        ->and($mediaItem->fresh())->not->toBeNull();

    $administrator = User::factory()->create(['is_administrator' => true]);
    $this->actingAs($administrator)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasNoErrors();

    expect($path)->not->toBeFile()
        ->and($mediaItem->fresh())->toBeNull();
});

it('requires the irreversible acknowledgement without changing bytes or database records', function () {
    $owner = User::factory()->create();
    [$mediaItem, $upload, $mediaFile, $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => false])
        ->assertSessionHasErrors('deletion_confirmed');

    expect(file_get_contents($path))->toBe('old-primary!')
        ->and($mediaItem->fresh())->not->toBeNull()
        ->and($upload->fresh())->not->toBeNull()
        ->and($mediaFile->fresh())->not->toBeNull();
});

it('blocks deletion while a related upload is active or failed', function (UploadStatus $status) {
    $owner = User::factory()->create();
    [$mediaItem, , , $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);
    $extraUpload = Upload::factory()->for($owner)->for($mediaItem)->create(['disk_id' => 'movies']);
    Upload::query()->whereKey($extraUpload)->update(['status' => $status->value]);

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasErrors('deletion');

    expect($path)->toBeFile()
        ->and($mediaItem->fresh())->not->toBeNull();
})->with([
    UploadStatus::Pending,
    UploadStatus::Uploading,
    UploadStatus::Paused,
    UploadStatus::Processing,
    UploadStatus::Failed,
]);

it('fails closed when the primary is missing changed or a symlink before a claim', function (string $condition) {
    $owner = User::factory()->create();
    [$mediaItem, , , $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);

    if ($condition === 'missing') {
        unlink($path);
    } elseif ($condition === 'changed') {
        file_put_contents($path, 'changed-primary');
    } else {
        unlink($path);
        symlink($this->movieLibraryDisk.'/.media-upload-manager/disk.json', $path);
    }

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasErrors('deletion');

    expect($mediaItem->fresh())->not->toBeNull()
        ->and($mediaItem->fresh()?->deletion_claim)->toBeNull();
})->with(['missing', 'changed', 'symlink']);

it('fails closed when its configured disk identity is unavailable', function () {
    $owner = User::factory()->create();
    [$mediaItem, , , $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);
    unlink($this->movieLibraryDisk.'/.media-upload-manager/disk.json');

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasErrors('deletion');

    expect($path)->toBeFile()
        ->and($mediaItem->fresh())->not->toBeNull();
});

it('retries a durable post-unlink deletion claim to convergence', function () {
    $owner = User::factory()->create();
    [$mediaItem, , $mediaFile, $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);
    $stat = lstat($path);
    $claim = TrackedMovieDeletionClaim::forPrimary(
        mediaItemId: $mediaItem->getKey(),
        actorUserId: $owner->getKey(),
        title: $mediaItem->title,
        mediaFileId: $mediaFile->getKey(),
        sourceUploadId: $mediaFile->source_upload_id,
        diskId: $mediaFile->disk_id,
        relativePath: $mediaFile->relative_path,
        sizeBytes: $mediaFile->size_bytes,
        deviceId: $stat['dev'],
        inodeId: $stat['ino'],
    );
    $mediaItem->update([
        'deletion_claim' => $claim->toArray(),
        'deletion_requested_at' => now(),
    ]);
    unlink($path);

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasNoErrors();

    expect($mediaItem->fresh())->toBeNull()
        ->and($mediaFile->fresh())->toBeNull();
});

it('deletes orphan records only for an administrator or the owner of every related upload', function () {
    $owner = User::factory()->create();
    $orphan = MediaItem::factory()->create(['title' => 'Owned Orphan']);
    $upload = Upload::factory()->for($owner)->for($orphan)->create(['disk_id' => 'movies']);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Cancelled->value,
        'cancelled_at' => now(),
    ]);

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $orphan), ['deletion_confirmed' => true])
        ->assertSessionHasNoErrors();
    expect($orphan->fresh())->toBeNull();

    $ownerless = MediaItem::factory()->create(['title' => 'Ownerless Orphan']);
    $this->actingAs($owner)
        ->delete(route('movies.destroy', $ownerless), ['deletion_confirmed' => true])
        ->assertForbidden();
    expect($ownerless->fresh())->not->toBeNull();

    $administrator = User::factory()->create(['is_administrator' => true]);
    $this->actingAs($administrator)
        ->delete(route('movies.destroy', $ownerless), ['deletion_confirmed' => true])
        ->assertSessionHasNoErrors();
    expect($ownerless->fresh())->toBeNull();
});

it('blocks deletion when a related tus stage or metadata sidecar still exists', function (string $residue) {
    $owner = User::factory()->create();
    [$mediaItem, $upload, , $path] = createMovieLibraryPrimary($owner, $this->movieLibraryDisk);

    if ($residue === 'stage') {
        file_put_contents($this->movieLibraryDisk.'/'.$upload->staging_relative_path, 'residue');
    } else {
        file_put_contents($this->movieLibraryMetadata.'/'.$upload->uuid.'.info', 'residue');
    }

    $this->actingAs($owner)
        ->delete(route('movies.destroy', $mediaItem), ['deletion_confirmed' => true])
        ->assertSessionHasErrors('deletion');

    expect($path)->toBeFile()
        ->and($mediaItem->fresh())->not->toBeNull();
})->with(['stage', 'metadata']);
