<?php

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\MediaItemReidentification;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function reidentificationTmdbPayload(
    int $id = 900001,
    string $title = 'Correct Movie',
    string $imdbId = 'tt9000001',
): array {
    return [
        'id' => $id,
        'imdb_id' => $imdbId,
        'title' => $title,
        'original_title' => $title,
        'release_date' => '2001-02-03',
        'overview' => 'The corrected movie.',
        'poster_path' => '/correct.jpg',
        'original_language' => 'en',
        'runtime' => 121,
        'status' => 'Released',
        'tagline' => 'Correct at last.',
        'vote_average' => 7.5,
        'vote_count' => 100,
        'genres' => [['id' => 18, 'name' => 'Drama']],
    ];
}

function configureReidentificationDisk(string $root, string $metadata): void
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
    config()->set('upload.tus_metadata_path', $metadata);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array{MediaItem, MediaFile, string} */
function createReidentificationPrimary(User $owner, string $root): array
{
    $mediaItem = MediaItem::factory()->create([
        'tmdb_id' => 800001,
        'imdb_id' => 'tt8000001',
        'title' => 'Wrong Movie',
        'original_title' => 'Wrong Movie',
        'release_year' => 1999,
    ]);
    $relativePath = 'Wrong Movie (1999) [tmdbid-800001]/Wrong Movie (1999) [tmdbid-800001].mkv';
    $contents = 'tracked-movie-bytes';
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
    $mediaFile = MediaFile::factory()->forUpload($upload->refresh())->create([
        'disk_id' => 'movies',
        'relative_path' => $relativePath,
        'size_bytes' => strlen($contents),
    ]);
    $mediaItem->update(['current_media_file_id' => $mediaFile->id]);
    $path = $root.'/'.$relativePath;
    (new Filesystem)->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, $contents);

    return [$mediaItem->refresh(), $mediaFile, $path];
}

beforeEach(function () {
    $this->reidentificationFilesystem = new Filesystem;
    $this->reidentificationBase = storage_path('framework/testing/reidentification-'.bin2hex(random_bytes(6)));
    $this->reidentificationRoot = $this->reidentificationBase.'/movies';
    $this->reidentificationMetadata = $this->reidentificationBase.'/metadata';
    $this->reidentificationFilesystem->makeDirectory(
        $this->reidentificationRoot.'/.media-upload-manager/incoming',
        0750,
        true,
    );
    $this->reidentificationFilesystem->makeDirectory($this->reidentificationMetadata, 0750, true);
    file_put_contents(
        $this->reidentificationRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies'),
    );
    configureReidentificationDisk($this->reidentificationRoot, $this->reidentificationMetadata);
    config()->set('services.tmdb', [
        'token' => 'test-tmdb-token',
        'language' => 'en-US',
        'base_url' => 'https://api.themoviedb.org/3',
        'cache_ttl' => 86400,
        'connect_timeout' => 1,
        'request_timeout' => 1,
    ]);
    Cache::clear();
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(
        str_contains($request->url(), '/movie/900002')
            ? reidentificationTmdbPayload(900002, 'Another Movie', 'tt9000002')
            : reidentificationTmdbPayload(),
    ));
});

afterEach(function () {
    $this->reidentificationFilesystem->deleteDirectory($this->reidentificationBase);
});

it('allows only administrators to preview and confirm re-identification', function () {
    [$movie] = createReidentificationPrimary(User::factory()->create(), $this->reidentificationRoot);

    $this->post(route('movies.reidentification.preview', $movie), ['tmdb_id' => 900001])
        ->assertRedirect(route('login'));

    $user = User::factory()->create();
    $this->actingAs($user)
        ->postJson(route('movies.reidentification.preview', $movie), ['tmdb_id' => 900001])
        ->assertForbidden();
    $this->actingAs($user)
        ->post(route('movies.reidentify', $movie), [
            'tmdb_id' => 900001,
            'reidentification_confirmed' => true,
        ])
        ->assertForbidden();
});

it('previews the current identity canonical destination and blocker without mutation', function () {
    [$movie, , $path] = createReidentificationPrimary(User::factory()->create(), $this->reidentificationRoot);
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->postJson(route('movies.reidentification.preview', $movie), ['tmdb_id' => 900001])
        ->assertOk()
        ->assertJsonPath('data.current_identity.tmdb_id', 800001)
        ->assertJsonPath('data.proposed_identity.tmdb_id', 900001)
        ->assertJsonPath('data.proposed_relative_path', 'Correct Movie (2001) [tmdbid-900001]/Correct Movie (2001) [tmdbid-900001].mkv')
        ->assertJsonPath('data.disk.id', 'movies')
        ->assertJsonPath('data.eligible', true)
        ->assertJsonPath('data.blocker', null);

    expect($movie->fresh()?->tmdb_id)->toBe(800001)
        ->and($path)->toBeFile()
        ->and(MediaItemReidentification::query()->count())->toBe(0);
});

it('re-identifies the exact primary preserving history sidecars and path-free audit records', function () {
    Log::spy();
    [$movie, $oldMediaFile, $oldPath] = createReidentificationPrimary(
        User::factory()->create(),
        $this->reidentificationRoot,
    );
    $oldDirectory = dirname($oldPath);
    file_put_contents($oldDirectory.'/poster.jpg', 'keep-poster');
    file_put_contents($oldDirectory.'/movie.nfo', 'keep-nfo');
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->post(route('movies.reidentify', $movie), [
            'tmdb_id' => 900001,
            'reidentification_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    $movie->refresh();
    $operation = MediaItemReidentification::query()->sole();
    $newMediaFile = $movie->currentMediaFile()->sole();
    $newPath = $this->reidentificationRoot.'/'.$newMediaFile->relative_path;

    expect($movie->tmdb_id)->toBe(900001)
        ->and($movie->title)->toBe('Correct Movie')
        ->and($oldPath)->not->toBeFile()
        ->and($newPath)->toBeFile()
        ->and(file_get_contents($newPath))->toBe('tracked-movie-bytes')
        ->and(file_get_contents($oldDirectory.'/poster.jpg'))->toBe('keep-poster')
        ->and(file_get_contents($oldDirectory.'/movie.nfo'))->toBe('keep-nfo')
        ->and($operation->status)->toBe('completed')
        ->and($operation->old_metadata_snapshot['tmdb_id'])->toBe(800001)
        ->and($operation->new_metadata_snapshot['tmdb_id'])->toBe(900001)
        ->and($oldMediaFile->fresh()?->removal_reason)->toBe('reidentified')
        ->and($oldMediaFile->fresh()?->active_path_key)->toBeNull()
        ->and($newMediaFile->import_provenance['type'])->toBe('reidentification')
        ->and(MediaFile::query()->whereBelongsTo($movie)->count())->toBe(2);

    Log::shouldHaveReceived('notice')->twice()->with('security.audit', Mockery::on(
        fn (array $context): bool => in_array($context['event'], [
            'movie_reidentification_confirmed',
            'movie_reidentification_completed',
        ], true)
            && $context['media_item_id'] === $movie->id
            && ! array_key_exists('relative_path', $context)
            && ! array_key_exists('source_relative_path', $context)
            && ! array_key_exists('destination_relative_path', $context),
    ));
});

it('allows a database-only correction for an orphan and removes an empty target placeholder', function () {
    $orphan = MediaItem::factory()->create([
        'tmdb_id' => 800001,
        'imdb_id' => 'tt8000001',
        'title' => 'Wrong Movie',
    ]);
    $placeholder = MediaItem::factory()->create([
        'tmdb_id' => 900001,
        'imdb_id' => 'tt9000001',
        'title' => 'Placeholder',
    ]);

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('movies.reidentify', $orphan), [
            'tmdb_id' => 900001,
            'reidentification_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($orphan->fresh()?->tmdb_id)->toBe(900001)
        ->and($placeholder->fresh())->toBeNull()
        ->and(MediaFile::query()->where('media_item_id', $orphan->id)->count())->toBe(0)
        ->and(MediaItemReidentification::query()->sole()->source_media_file_id)->toBeNull();
});

it('purges re-identification history with the movie after a later permanent deletion', function () {
    [$movie] = createReidentificationPrimary(User::factory()->create(), $this->reidentificationRoot);
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->post(route('movies.reidentify', $movie), [
            'tmdb_id' => 900001,
            'reidentification_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    $movie->refresh();
    $currentPath = $this->reidentificationRoot.'/'.$movie->currentMediaFile()->sole()->relative_path;

    $this->actingAs($administrator)
        ->delete(route('movies.destroy', $movie), ['deletion_confirmed' => true])
        ->assertSessionHasNoErrors();

    expect($movie->fresh())->toBeNull()
        ->and($currentPath)->not->toBeFile()
        ->and(MediaItemReidentification::query()->where('media_item_id', $movie->id)->count())->toBe(0)
        ->and(MediaFile::query()->where('media_item_id', $movie->id)->count())->toBe(0)
        ->and(Upload::query()->where('media_item_id', $movie->id)->count())->toBe(0);
});

it('reports upload deletion disk source destination identity and stale-primary blockers', function (string $condition, string $code) {
    [$movie, $mediaFile, $path] = createReidentificationPrimary(
        User::factory()->create(),
        $this->reidentificationRoot,
    );

    if ($condition === 'active_upload' || $condition === 'failed_upload') {
        $upload = Upload::factory()->for($movie)->create(['disk_id' => 'movies']);
        Upload::query()->whereKey($upload)->update([
            'status' => $condition === 'failed_upload' ? UploadStatus::Failed->value : UploadStatus::Uploading->value,
        ]);
    } elseif ($condition === 'deletion') {
        $movie->update([
            'deletion_claim' => ['version' => 1],
            'deletion_requested_at' => now(),
        ]);
    } elseif ($condition === 'disk') {
        unlink($this->reidentificationRoot.'/.media-upload-manager/disk.json');
    } elseif ($condition === 'missing_source') {
        unlink($path);
    } elseif ($condition === 'changed_source') {
        file_put_contents($path, 'changed');
    } elseif ($condition === 'symlink_source') {
        unlink($path);
        symlink($this->reidentificationRoot.'/.media-upload-manager/disk.json', $path);
    } elseif ($condition === 'destination') {
        $destination = $this->reidentificationRoot.'/Correct Movie (2001) [tmdbid-900001]/Correct Movie (2001) [tmdbid-900001].mkv';
        $this->reidentificationFilesystem->makeDirectory(dirname($destination), 0750, true);
        file_put_contents($destination, 'occupied');
    } elseif ($condition === 'identity') {
        MediaItem::factory()->create([
            'tmdb_id' => 900001,
            'imdb_id' => 'tt9000001',
        ]);
        Upload::factory()->create(['media_item_id' => MediaItem::query()->where('tmdb_id', 900001)->value('id')]);
    } elseif ($condition === 'stale_primary') {
        MediaItem::withoutEvents(fn () => $movie->update(['current_media_file_id' => null]));
        expect($mediaFile->fresh()?->active_path_key)->not->toBeNull();
    }

    $this->actingAs(User::factory()->administrator()->create())
        ->postJson(route('movies.reidentification.preview', $movie), ['tmdb_id' => 900001])
        ->assertOk()
        ->assertJsonPath('data.eligible', false)
        ->assertJsonPath('data.blocker.code', $code);
})->with([
    ['active_upload', 'active_upload'],
    ['failed_upload', 'failed_upload'],
    ['deletion', 'deletion_claimed'],
    ['disk', 'disk_unavailable'],
    ['missing_source', 'source_changed'],
    ['changed_source', 'source_changed'],
    ['symlink_source', 'unsafe_path'],
    ['destination', 'destination_occupied'],
    ['identity', 'identity_conflict'],
    ['stale_primary', 'stale_primary'],
]);

it('recovers both-linked and destination-only retry states without switching targets', function (bool $removeSource) {
    [$movie, $oldMediaFile, $oldPath] = createReidentificationPrimary(
        User::factory()->create(),
        $this->reidentificationRoot,
    );
    $administrator = User::factory()->administrator()->create();
    $payload = reidentificationTmdbPayload();
    $newSnapshot = [
        'tmdb_id' => $payload['id'],
        'imdb_id' => $payload['imdb_id'],
        'title' => $payload['title'],
        'original_title' => $payload['original_title'],
        'release_date' => $payload['release_date'],
        'release_year' => 2001,
        'overview' => $payload['overview'],
        'poster_path' => $payload['poster_path'],
        'original_language' => $payload['original_language'],
        'metadata_version' => 1,
        'metadata_snapshot' => [
            'tmdb_id' => $payload['id'],
            'imdb_id' => $payload['imdb_id'],
            'title' => $payload['title'],
            'original_title' => $payload['original_title'],
            'release_date' => $payload['release_date'],
            'release_year' => 2001,
            'overview' => $payload['overview'],
            'poster_path' => $payload['poster_path'],
            'original_language' => $payload['original_language'],
            'runtime' => $payload['runtime'],
            'status' => $payload['status'],
            'tagline' => $payload['tagline'],
            'vote_average' => $payload['vote_average'],
            'vote_count' => $payload['vote_count'],
            'genres' => $payload['genres'],
        ],
    ];
    $destination = app(JellyfinMoviePathBuilder::class)->build(new MediaItem($newSnapshot), basename($oldPath));
    $destinationPath = $this->reidentificationRoot.'/'.$destination->relativePath;
    $this->reidentificationFilesystem->makeDirectory(dirname($destinationPath), 0750, true);
    link($oldPath, $destinationPath);
    $stat = lstat($oldPath);
    $operation = MediaItemReidentification::query()->create([
        'media_item_id' => $movie->id,
        'actor_user_id' => $administrator->id,
        'source_media_file_id' => $oldMediaFile->id,
        'source_upload_id' => $oldMediaFile->source_upload_id,
        'old_metadata_snapshot' => $movie->only([
            'tmdb_id', 'imdb_id', 'title', 'original_title', 'release_date', 'release_year',
            'overview', 'poster_path', 'original_language', 'metadata_version', 'metadata_snapshot',
        ]),
        'new_metadata_snapshot' => $newSnapshot,
        'disk_id' => 'movies',
        'source_relative_path' => $oldMediaFile->relative_path,
        'destination_relative_path' => $destination->relativePath,
        'size_bytes' => $oldMediaFile->size_bytes,
        'device_id' => $stat['dev'],
        'inode_id' => $stat['ino'],
        'status' => 'pending',
        'claimed_at' => now(),
    ]);
    $operation->update([
        'status' => 'failed',
        'error_code' => 'interrupted',
        'error_detail' => 'Interrupted at the filesystem boundary.',
        'failed_at' => now(),
    ]);

    if ($removeSource) {
        unlink($oldPath);
    }

    $this->actingAs($administrator)
        ->post(route('movies.reidentify', $movie), [
            'tmdb_id' => 900001,
            'reidentification_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($oldPath)->not->toBeFile()
        ->and($destinationPath)->toBeFile()
        ->and($movie->fresh()?->tmdb_id)->toBe(900001)
        ->and(MediaItemReidentification::query()->sole()->status)->toBe('completed');
})->with([false, true]);

it('does not allow a claimed retry to switch targets', function () {
    [$movie, $oldMediaFile, $oldPath] = createReidentificationPrimary(
        User::factory()->create(),
        $this->reidentificationRoot,
    );
    $administrator = User::factory()->administrator()->create();
    $stat = lstat($oldPath);
    $target = reidentificationTmdbPayload();
    $snapshot = [
        ...$movie->only([
            'tmdb_id', 'imdb_id', 'title', 'original_title', 'release_date', 'release_year',
            'overview', 'poster_path', 'original_language', 'metadata_version', 'metadata_snapshot',
        ]),
        'tmdb_id' => $target['id'],
        'imdb_id' => $target['imdb_id'],
        'title' => $target['title'],
    ];
    $operation = MediaItemReidentification::query()->create([
        'media_item_id' => $movie->id,
        'actor_user_id' => $administrator->id,
        'source_media_file_id' => $oldMediaFile->id,
        'source_upload_id' => $oldMediaFile->source_upload_id,
        'old_metadata_snapshot' => $movie->metadata_snapshot,
        'new_metadata_snapshot' => $snapshot,
        'disk_id' => 'movies',
        'source_relative_path' => $oldMediaFile->relative_path,
        'destination_relative_path' => 'Correct Movie (2001) [tmdbid-900001]/Correct Movie (2001) [tmdbid-900001].mkv',
        'size_bytes' => $oldMediaFile->size_bytes,
        'device_id' => $stat['dev'],
        'inode_id' => $stat['ino'],
        'status' => 'pending',
        'claimed_at' => now(),
    ]);
    $operation->update([
        'status' => 'failed',
        'error_code' => 'interrupted',
        'error_detail' => 'Interrupted.',
        'failed_at' => now(),
    ]);
    $this->actingAs($administrator)
        ->post(route('movies.reidentify', $movie), [
            'tmdb_id' => 900002,
            'reidentification_confirmed' => true,
        ])
        ->assertSessionHasErrors('reidentification');

    expect($movie->fresh()?->tmdb_id)->toBe(800001)
        ->and($oldPath)->toBeFile();
});
