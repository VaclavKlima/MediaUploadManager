<?php

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\EpisodeRenameOperation;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\UploadConfiguration;
use App\Support\Series\JellyfinSeriesPathBuilder;
use Illuminate\Filesystem\Filesystem;

function configureSeriesManagementDisk(string $root, string $metadata): void
{
    config()->set('media', [
        'disks' => [[
            'id' => 'series_a',
            'label' => 'Series A',
            'series_path' => $root,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload.tus_metadata_path', $metadata);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array{Series, SeriesSeason, SeriesEpisode, Upload, MediaFile, string} */
function createManagedSeriesPrimary(User $owner, string $root): array
{
    $series = Series::factory()->create([
        'name' => 'Severance',
        'first_air_year' => 2022,
        'home_disk_id' => 'series_a',
        'metadata_snapshot' => ['seasons' => [['season_number' => 1, 'name' => 'Season 1', 'episode_count' => 1]]],
    ]);
    $season = SeriesSeason::factory()->for($series)->create(['season_number' => 1]);
    $episode = SeriesEpisode::factory()->for($season, 'season')->create([
        'episode_number' => 1,
        'name' => 'Good News About Hell',
    ]);
    $relativePath = app(JellyfinSeriesPathBuilder::class)->build($episode, 'source.mkv')->relativePath;
    $contents = 'series-primary';
    $upload = Upload::factory()->for($owner)->forSeriesEpisode($episode)->create([
        'disk_id' => 'series_a',
        'target_relative_path' => $relativePath,
        'declared_size' => strlen($contents),
    ]);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Completed->value,
        'confirmed_offset' => strlen($contents),
        'completed_at' => now(),
        'expires_at' => null,
    ]);
    $file = MediaFile::factory()->forUpload($upload->refresh())->create([
        'disk_id' => 'series_a',
        'root_kind' => MediaRootKind::Series,
        'relative_path' => $relativePath,
        'size_bytes' => strlen($contents),
    ]);
    $episode->update(['current_media_file_id' => $file->id]);
    $path = $root.'/'.$relativePath;
    (new Filesystem)->makeDirectory(dirname($path), 0750, true);
    file_put_contents($path, $contents);

    return [$series, $season, $episode->refresh(), $upload->refresh(), $file, $path];
}

beforeEach(function () {
    $this->seriesManagementFilesystem = new Filesystem;
    $this->seriesManagementBase = storage_path('framework/testing/series-management-'.bin2hex(random_bytes(6)));
    $this->seriesManagementRoot = $this->seriesManagementBase.'/series';
    $this->seriesManagementMetadata = $this->seriesManagementBase.'/metadata';
    $this->seriesManagementFilesystem->makeDirectory($this->seriesManagementRoot.'/.media-upload-manager/incoming', 0750, true);
    $this->seriesManagementFilesystem->makeDirectory($this->seriesManagementMetadata, 0750, true);
    file_put_contents(
        $this->seriesManagementRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('series_a', MediaRootKind::Series),
    );
    configureSeriesManagementDisk($this->seriesManagementRoot, $this->seriesManagementMetadata);
});

afterEach(function () {
    $this->seriesManagementFilesystem->deleteDirectory($this->seriesManagementBase);
});

it('renames an uploaded episode through an immutable exact-file claim and preserves sidecars', function () {
    $owner = User::factory()->create();
    [$series, $season, $episode, , $oldFile, $oldPath] = createManagedSeriesPrimary($owner, $this->seriesManagementRoot);
    file_put_contents(dirname($oldPath).'/episode.nfo', 'keep');

    $this->actingAs($owner)
        ->patch(route('series.seasons.episodes.update', [$series, $season, $episode]), [
            'custom_name' => 'A Better Title',
            'rename_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    $episode->refresh();
    $newFile = $episode->currentMediaFile;

    expect($episode->custom_name)->toBe('A Better Title')
        ->and($newFile)->not->toBeNull()
        ->and($newFile?->id)->not->toBe($oldFile->id)
        ->and($newFile?->source_upload_id)->toBeNull()
        ->and($newFile?->imported_by_user_id)->toBe($owner->id)
        ->and($newFile?->import_provenance['source_upload_id'] ?? null)->toBe($oldFile->source_upload_id)
        ->and($this->seriesManagementRoot.'/'.$newFile?->relative_path)->toBeFile()
        ->and($oldPath)->not->toBeFile()
        ->and(dirname($oldPath).'/episode.nfo')->toBeFile()
        ->and(EpisodeRenameOperation::query()->where('status', 'completed')->count())->toBe(1);
});

it('allows only administrators to rename missing episodes', function () {
    $series = Series::factory()->create();
    $season = SeriesSeason::factory()->for($series)->create(['season_number' => 1]);
    $episode = SeriesEpisode::factory()->for($season, 'season')->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('series.seasons.episodes.update', [$series, $season, $episode]), [
            'custom_name' => 'Missing title',
            'rename_confirmed' => true,
        ])
        ->assertForbidden();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('series.seasons.episodes.update', [$series, $season, $episode]), [
            'custom_name' => 'Missing title',
            'rename_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($episode->fresh()?->custom_name)->toBe('Missing title');
});

it('blocks Series upload authorization while a durable operation is unresolved', function () {
    $owner = User::factory()->create();
    [, , $episode] = createManagedSeriesPrimary($owner, $this->seriesManagementRoot);
    $upload = Upload::factory()->for($owner)->forSeriesEpisode($episode)->create([
        'disk_id' => 'series_a',
    ]);
    EpisodeRenameOperation::query()->create([
        'series_episode_id' => $episode->id,
        'actor_user_id' => $owner->id,
        'old_custom_name' => null,
        'new_custom_name' => 'Pinned rename',
        'status' => 'failed',
        'claimed_at' => now(),
        'failed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson(route('uploads.authorization', $upload), [
            'filename' => $upload->original_filename,
            'declared_size' => $upload->declared_size,
            'last_modified_milliseconds' => $upload->last_modified_milliseconds,
            'fingerprint_first_sha256' => $upload->fingerprint_first_sha256,
            'fingerprint_last_sha256' => $upload->fingerprint_last_sha256,
        ])
        ->assertConflict()
        ->assertJsonPath('error', 'series_operation_unresolved');
});

it('deletes episode media for its owner while retaining TMDB structure and sidecars', function () {
    $owner = User::factory()->create();
    [$series, $season, $episode, $upload, $file, $path] = createManagedSeriesPrimary($owner, $this->seriesManagementRoot);
    file_put_contents(dirname($path).'/poster.jpg', 'keep');

    $this->actingAs($owner)
        ->delete(route('series.seasons.episodes.media.destroy', [$series, $season, $episode]), [
            'deletion_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($path)->not->toBeFile()
        ->and(dirname($path).'/poster.jpg')->toBeFile()
        ->and($episode->fresh())->not->toBeNull()
        ->and($episode->fresh()?->current_media_file_id)->toBeNull()
        ->and($upload->fresh())->not->toBeNull()
        ->and($file->fresh()?->removed_at)->not->toBeNull()
        ->and(SeriesDeletionOperation::query()->where('status', 'completed')->count())->toBe(1);
});

it('deletes only the selected current primary and preserves non-current tracked media', function () {
    $owner = User::factory()->create();
    [$series, $season, $episode, , , $currentPath] = createManagedSeriesPrimary($owner, $this->seriesManagementRoot);
    $nonCurrentContents = 'non-current-video';
    $nonCurrentRelativePath = 'Severance (2022) [tmdbid-'.$series->tmdb_id.']/Season 01/non-current.mkv';
    $nonCurrentUpload = Upload::factory()->for($owner)->forSeriesEpisode($episode)->create([
        'disk_id' => 'series_a',
        'target_relative_path' => $nonCurrentRelativePath,
        'declared_size' => strlen($nonCurrentContents),
    ]);
    Upload::query()->whereKey($nonCurrentUpload)->update([
        'status' => UploadStatus::Completed->value,
        'confirmed_offset' => strlen($nonCurrentContents),
        'completed_at' => now(),
        'expires_at' => null,
    ]);
    $nonCurrentFile = MediaFile::factory()->forUpload($nonCurrentUpload->refresh())->create([
        'disk_id' => 'series_a',
        'root_kind' => MediaRootKind::Series,
        'relative_path' => $nonCurrentRelativePath,
        'size_bytes' => strlen($nonCurrentContents),
    ]);
    $nonCurrentPath = $this->seriesManagementRoot.'/'.$nonCurrentRelativePath;
    $this->seriesManagementFilesystem->makeDirectory(dirname($nonCurrentPath), 0750, true, true);
    file_put_contents($nonCurrentPath, $nonCurrentContents);

    $this->actingAs($owner)
        ->delete(route('series.seasons.episodes.media.destroy', [$series, $season, $episode]), [
            'deletion_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($currentPath)->not->toBeFile()
        ->and($nonCurrentPath)->toBeFile()
        ->and($nonCurrentFile->fresh()?->active_path_key)->not->toBeNull()
        ->and($nonCurrentFile->fresh()?->removed_at)->toBeNull();
});

it('requires an administrator exact name and acknowledgement for whole-Show deletion', function () {
    $administrator = User::factory()->administrator()->create();
    [$series, $season, $episode, $upload, $file, $path] = createManagedSeriesPrimary($administrator, $this->seriesManagementRoot);

    $this->actingAs($administrator)
        ->delete(route('series.destroy', $series), [
            'confirmation_name' => 'Wrong',
            'deletion_confirmed' => true,
        ])
        ->assertSessionHasErrors('deletion');

    expect($path)->toBeFile();

    $this->actingAs($administrator)
        ->delete(route('series.destroy', $series), [
            'confirmation_name' => $series->name,
            'deletion_confirmed' => true,
        ])
        ->assertRedirect(route('series.index'))
        ->assertSessionHasNoErrors();

    expect($path)->not->toBeFile()
        ->and($series->fresh())->toBeNull()
        ->and($season->fresh())->toBeNull()
        ->and($episode->fresh())->toBeNull()
        ->and($upload->fresh())->toBeNull()
        ->and($file->fresh())->toBeNull()
        ->and(SeriesDeletionOperation::query()->where('status', 'completed')->count())->toBe(1);
});
