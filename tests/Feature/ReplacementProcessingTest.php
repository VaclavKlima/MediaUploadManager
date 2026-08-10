<?php

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\Exceptions\UploadProcessingException;
use App\Support\Media\FinalizeProcessedUpload;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

function configureReplacementProcessing(string $diskA, string $diskB, string $metadataPath): void
{
    config()->set('media', [
        'disks' => [[
            'id' => 'movies_a',
            'label' => 'Movies A',
            'path' => $diskA,
            'reserve_gib' => '0',
        ], [
            'id' => 'movies_b',
            'label' => 'Movies B',
            'path' => $diskB,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload.tus_metadata_path', $metadataPath);
    config()->set('upload.ffprobe_binary', 'ffprobe');
    config()->set('upload.ffprobe_timeout_seconds', '120');
    config()->set('upload.ffprobe_max_output_bytes', '1048576');
    config()->set('upload.ffprobe_max_streams', '64');
    config()->set('upload.processing_job_timeout_seconds', '180');
    config()->set('upload.processing_job_unique_seconds', '3600');
    config()->set('upload.processing_job_backoff_seconds', '15,60,180');
    config()->set('upload.processing_poll_interval_milliseconds', '1500');

    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

function replacementProbeJson(): string
{
    return json_encode([
        'streams' => [[
            'index' => 0,
            'codec_name' => 'hevc',
            'codec_type' => 'video',
            'width' => 3840,
            'height' => 2160,
            'disposition' => ['default' => 1],
        ]],
        'format' => ['format_name' => 'matroska,webm', 'duration' => '123.456'],
    ], JSON_THROW_ON_ERROR);
}

/** @return array{MediaItem, MediaFile} */
function replacementCurrentPrimary(User $owner, string $diskA): array
{
    $mediaItem = MediaItem::factory()->create([
        'title' => 'Test Movie',
        'release_year' => 2026,
        'tmdb_id' => 123,
    ]);
    $relativePath = 'Test Movie (2026) [tmdbid-123]/Test Movie (2026) [tmdbid-123].mkv';
    $sourceUpload = Upload::factory()->for($owner)->for($mediaItem)->create([
        'disk_id' => 'movies_a',
        'target_relative_path' => $relativePath,
        'declared_size' => 12,
    ]);
    Upload::query()->whereKey($sourceUpload)->update([
        'status' => UploadStatus::Completed->value,
        'confirmed_offset' => 12,
        'completed_at' => now(),
        'expires_at' => null,
    ]);
    $mediaFile = MediaFile::factory()->forUpload($sourceUpload->refresh())->create([
        'disk_id' => 'movies_a',
        'relative_path' => $relativePath,
        'size_bytes' => 12,
    ]);
    $mediaItem->update(['current_media_file_id' => $mediaFile->getKey()]);

    (new Filesystem)->makeDirectory(dirname($diskA.'/'.$relativePath), 0750, true);
    file_put_contents($diskA.'/'.$relativePath, 'old-primary!');

    return [$mediaItem, $mediaFile];
}

function replacementProcessingUpload(
    User $actor,
    MediaItem $mediaItem,
    MediaFile $oldMediaFile,
    string $diskId,
    string $root,
    string $metadataPath,
    string $targetRelativePath,
): Upload {
    $extension = pathinfo($targetRelativePath, PATHINFO_EXTENSION);
    $upload = Upload::factory()->for($actor)->for($mediaItem)->create([
        'disk_id' => $diskId,
        'target_relative_path' => $targetRelativePath,
        'original_filename' => 'replacement.'.$extension,
        'extension' => $extension,
        'declared_size' => 12,
        'replaces_media_file_id' => $oldMediaFile->getKey(),
        'replacement_confirmed_at' => now(),
    ]);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Processing->value,
        'confirmed_offset' => 12,
        'tus_resource_id' => $upload->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'processing_at' => now(),
    ]);

    $stagePath = $root.'/'.$upload->staging_relative_path;
    file_put_contents($stagePath, 'new-primary!');
    file_put_contents($metadataPath.'/'.$upload->uuid.'.info', json_encode([
        'ID' => $upload->uuid,
        'Size' => 12,
        'SizeIsDeferred' => false,
        'MetaData' => ['upload_uuid' => $upload->uuid],
        'IsPartial' => false,
        'IsFinal' => false,
        'PartialUploads' => null,
        'Storage' => ['Path' => $stagePath],
    ], JSON_THROW_ON_ERROR));

    return $upload->refresh();
}

/** @return array<string, mixed> */
function replacementClaim(Upload $upload, MediaFile $oldMediaFile, string $newRoot, string $oldRoot): array
{
    $stage = lstat($newRoot.'/'.$upload->staging_relative_path);
    $old = lstat($oldRoot.'/'.$oldMediaFile->relative_path);

    return [
        'version' => 2,
        'expected_size' => 12,
        'device_id' => $stage['dev'],
        'inode_id' => $stage['ino'],
        'replacement' => [
            'media_file_id' => $oldMediaFile->getKey(),
            'source_upload_id' => $oldMediaFile->source_upload_id,
            'disk_id' => $oldMediaFile->disk_id,
            'relative_path' => $oldMediaFile->relative_path,
            'size_bytes' => 12,
            'device_id' => $old['dev'],
            'inode_id' => $old['ino'],
            'mode' => $oldMediaFile->disk_id === $upload->disk_id
                && $oldMediaFile->relative_path === $upload->target_relative_path
                    ? 'atomic_same_path_swap'
                    : 'finalize_then_delete',
        ],
        'container' => 'matroska',
        'duration_milliseconds' => 123_456,
        'video' => [['index' => 0, 'codec' => 'hevc', 'width' => 3840, 'height' => 2160, 'language' => null, 'disposition' => ['default' => true]]],
        'audio' => [],
        'snapshot' => ['format' => ['container' => 'matroska']],
    ];
}

beforeEach(function () {
    $this->replacementFilesystem = new Filesystem;
    $this->replacementBase = storage_path('framework/testing/replacement-'.bin2hex(random_bytes(6)));
    $this->replacementA = $this->replacementBase.'/a';
    $this->replacementB = $this->replacementBase.'/b';
    $this->replacementMetadata = $this->replacementBase.'/metadata';

    foreach (['movies_a' => $this->replacementA, 'movies_b' => $this->replacementB] as $diskId => $root) {
        $this->replacementFilesystem->makeDirectory($root.'/.media-upload-manager/incoming', 0750, true);
        file_put_contents($root.'/.media-upload-manager/disk.json', DiskMarker::encode($diskId));
    }

    $this->replacementFilesystem->makeDirectory($this->replacementMetadata, 0750, true);
    configureReplacementProcessing($this->replacementA, $this->replacementB, $this->replacementMetadata);
    Http::fake([
        'http://127.0.0.1:1080/uploads/tus/*' => Http::response('', 200, [
            'Upload-Offset' => '12',
            'Upload-Length' => '12',
        ]),
    ]);
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result(output: replacementProbeJson()));
});

afterEach(function () {
    $this->replacementFilesystem->deleteDirectory($this->replacementBase);
});

it('atomically replaces the exact same path and preserves every unrelated sidecar', function () {
    Log::spy();
    $owner = User::factory()->create();
    [$mediaItem, $oldMediaFile] = replacementCurrentPrimary($owner, $this->replacementA);
    $oldPath = $this->replacementA.'/'.$oldMediaFile->relative_path;
    $directory = dirname($oldPath);
    file_put_contents($directory.'/movie.nfo', 'nfo-unchanged');
    file_put_contents($directory.'/poster.jpg', 'poster-unchanged');
    $upload = replacementProcessingUpload(
        $owner,
        $mediaItem,
        $oldMediaFile,
        'movies_a',
        $this->replacementA,
        $this->replacementMetadata,
        $oldMediaFile->relative_path,
    );
    $stageInode = lstat($this->replacementA.'/'.$upload->staging_relative_path)['ino'];

    app(FinalizeProcessedUpload::class)->process($upload);
    app(FinalizeProcessedUpload::class)->process($upload->refresh());

    $newMediaFile = $upload->mediaFile()->sole();

    expect($upload->refresh()->status)->toBe(UploadStatus::Completed)
        ->and($mediaItem->refresh()->current_media_file_id)->toBe($newMediaFile->getKey())
        ->and(file_get_contents($oldPath))->toBe('new-primary!')
        ->and(lstat($oldPath)['ino'])->toBe($stageInode)
        ->and(file_get_contents($directory.'/movie.nfo'))->toBe('nfo-unchanged')
        ->and(file_get_contents($directory.'/poster.jpg'))->toBe('poster-unchanged')
        ->and($oldMediaFile->refresh()->replaced_by_media_file_id)->toBe($newMediaFile->getKey())
        ->and($oldMediaFile->replaced_at)->not->toBeNull()
        ->and($oldMediaFile->removed_at)->not->toBeNull()
        ->and($oldMediaFile->removal_reason)->toBe('replaced_without_backup')
        ->and($oldMediaFile->active_path_key)->toBeNull()
        ->and(MediaFile::query()->count())->toBe(2);
    Log::shouldHaveReceived('notice')->once()->with('security.audit', Mockery::on(
        fn (array $context): bool => $context['event'] === 'media_replacement_completed'
            && $context['upload_id'] === $upload->getKey()
            && ! array_key_exists('token', $context),
    ));
});

it('finalizes first then deletes only the exact old file for same-directory and cross-disk replacements', function (string $layout) {
    $owner = User::factory()->create();
    [$mediaItem, $oldMediaFile] = replacementCurrentPrimary($owner, $this->replacementA);
    $oldPath = $this->replacementA.'/'.$oldMediaFile->relative_path;
    $oldDirectory = dirname($oldPath);
    file_put_contents($oldDirectory.'/movie.nfo', 'keep-nfo');
    file_put_contents($oldDirectory.'/subtitle.srt', 'keep-subtitle');
    $diskId = $layout === 'cross-disk' ? 'movies_b' : 'movies_a';
    $root = $layout === 'cross-disk' ? $this->replacementB : $this->replacementA;
    $target = $layout === 'cross-disk'
        ? $oldMediaFile->relative_path
        : preg_replace('/\.mkv$/', '.mp4', $oldMediaFile->relative_path);
    expect($target)->toBeString();
    $upload = replacementProcessingUpload(
        $owner,
        $mediaItem,
        $oldMediaFile,
        $diskId,
        $root,
        $this->replacementMetadata,
        $target,
    );

    app(FinalizeProcessedUpload::class)->process($upload);

    expect(file_exists($oldPath))->toBeFalse()
        ->and(file_get_contents($root.'/'.$target))->toBe('new-primary!')
        ->and(file_get_contents($oldDirectory.'/movie.nfo'))->toBe('keep-nfo')
        ->and(file_get_contents($oldDirectory.'/subtitle.srt'))->toBe('keep-subtitle')
        ->and($mediaItem->refresh()->current_media_file_id)->toBe($upload->mediaFile()->sole()->getKey())
        ->and($oldMediaFile->refresh()->removed_at)->not->toBeNull();
})->with(['same-directory', 'cross-disk']);

it('recovers deterministically after each destructive filesystem boundary', function (string $crashPoint) {
    $owner = User::factory()->create();
    [$mediaItem, $oldMediaFile] = replacementCurrentPrimary($owner, $this->replacementA);
    $samePath = $crashPoint === 'after atomic rename';
    $diskId = $samePath ? 'movies_a' : 'movies_b';
    $root = $samePath ? $this->replacementA : $this->replacementB;
    $upload = replacementProcessingUpload(
        $owner,
        $mediaItem,
        $oldMediaFile,
        $diskId,
        $root,
        $this->replacementMetadata,
        $oldMediaFile->relative_path,
    );
    $claim = replacementClaim($upload, $oldMediaFile, $root, $this->replacementA);
    Upload::query()->whereKey($upload)->update([
        'processing_claim' => json_encode($claim, JSON_THROW_ON_ERROR),
        'finalization_started_at' => now(),
    ]);
    $stagePath = $root.'/'.$upload->staging_relative_path;
    $targetPath = $root.'/'.$upload->target_relative_path;
    $oldPath = $this->replacementA.'/'.$oldMediaFile->relative_path;

    if ($samePath) {
        rename($stagePath, $targetPath);
    } else {
        $this->replacementFilesystem->makeDirectory(dirname($targetPath), 0750, true);
        link($stagePath, $targetPath);

        if (in_array($crashPoint, ['after staging unlink', 'after old deletion'], true)) {
            unlink($stagePath);
        }

        if ($crashPoint === 'after old deletion') {
            unlink($oldPath);
        }
    }

    app(FinalizeProcessedUpload::class)->process($upload->refresh());

    expect($upload->refresh()->status)->toBe(UploadStatus::Completed)
        ->and(file_get_contents($targetPath))->toBe('new-primary!')
        ->and($mediaItem->refresh()->current_media_file_id)->toBe($upload->mediaFile()->sole()->getKey())
        ->and($oldMediaFile->refresh()->removed_at)->not->toBeNull();
    Process::assertNothingRan();
})->with(['after atomic rename', 'after hard link', 'after staging unlink', 'after old deletion']);

it('leaves the old primary untouched when validation fails or its identity changes', function (string $failure) {
    $owner = User::factory()->create();
    [$mediaItem, $oldMediaFile] = replacementCurrentPrimary($owner, $this->replacementA);
    $oldPath = $this->replacementA.'/'.$oldMediaFile->relative_path;
    $upload = replacementProcessingUpload(
        $owner,
        $mediaItem,
        $oldMediaFile,
        'movies_b',
        $this->replacementB,
        $this->replacementMetadata,
        $oldMediaFile->relative_path,
    );

    if ($failure === 'probe') {
        Process::fake(fn () => Process::result(errorOutput: 'invalid', exitCode: 1));
        (new ProcessCompletedUpload($upload->getKey()))->handle(
            app(FinalizeProcessedUpload::class),
            app(TransitionUploadStatus::class),
        );
        expect($upload->refresh()->status)->toBe(UploadStatus::Failed);
    } else {
        file_put_contents($oldPath, 'bad');
        expect(fn () => app(FinalizeProcessedUpload::class)->process($upload))
            ->toThrow(UploadProcessingException::class);
    }

    expect(file_exists($oldPath))->toBeTrue()
        ->and($mediaItem->refresh()->current_media_file_id)->toBe($oldMediaFile->getKey())
        ->and($oldMediaFile->refresh()->removed_at)->toBeNull()
        ->and($upload->mediaFile()->exists())->toBeFalse();
})->with(['probe', 'changed old file']);

it('forbids discarding an ambiguous failed replacement after its claim exists', function () {
    $owner = User::factory()->create();
    [$mediaItem, $oldMediaFile] = replacementCurrentPrimary($owner, $this->replacementA);
    $upload = replacementProcessingUpload(
        $owner,
        $mediaItem,
        $oldMediaFile,
        'movies_b',
        $this->replacementB,
        $this->replacementMetadata,
        $oldMediaFile->relative_path,
    );
    $claim = replacementClaim($upload, $oldMediaFile, $this->replacementB, $this->replacementA);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Failed->value,
        'processing_claim' => json_encode($claim, JSON_THROW_ON_ERROR),
        'finalization_started_at' => now(),
        'error_code' => 'replacement_delete_failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->getJson(route('uploads.show', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.failure.can_retry', true)
        ->assertJsonPath('data.failure.can_discard', false)
        ->assertJsonPath('data.replacement.relative_path', $oldMediaFile->relative_path);
    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', $upload))
        ->assertConflict()
        ->assertJsonPath('error', 'upload_discard_forbidden');
});

it('discards a pre-claim same-path replacement while preserving the exact current primary', function () {
    $owner = User::factory()->create();
    [$mediaItem, $oldMediaFile] = replacementCurrentPrimary($owner, $this->replacementA);
    $oldPath = $this->replacementA.'/'.$oldMediaFile->relative_path;
    $upload = replacementProcessingUpload(
        $owner,
        $mediaItem,
        $oldMediaFile,
        'movies_a',
        $this->replacementA,
        $this->replacementMetadata,
        $oldMediaFile->relative_path,
    );
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Failed->value,
        'error_code' => 'replacement_database_conflict',
        'error_detail' => 'The tracked replacement primary is no longer active and exact.',
        'failed_at' => now(),
    ]);
    $stagePath = $this->replacementA.'/'.$upload->staging_relative_path;
    $sidecarPath = $this->replacementMetadata.'/'.$upload->uuid.'.info';

    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    expect(file_get_contents($oldPath))->toBe('old-primary!')
        ->and($stagePath)->not->toBeFile()
        ->and($sidecarPath)->not->toBeFile()
        ->and($mediaItem->refresh()->current_media_file_id)->toBe($oldMediaFile->getKey())
        ->and($oldMediaFile->refresh()->removed_at)->toBeNull()
        ->and($upload->refresh()->status)->toBe(UploadStatus::Cancelled);
});
