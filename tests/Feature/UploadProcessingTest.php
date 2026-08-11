<?php

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\MediaFile;
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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function configureProcessingTest(string $root, string $metadataPath): void
{
    config()->set('media', [
        'disks' => [[
            'id' => 'movies_a',
            'label' => 'Movies A',
            'path' => $root,
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

function processingProbeJson(): string
{
    return json_encode([
        'streams' => [[
            'index' => 0,
            'codec_name' => 'hevc',
            'codec_type' => 'video',
            'width' => 3840,
            'height' => 2160,
            'tags' => ['language' => 'eng'],
            'disposition' => ['default' => 1],
        ], [
            'index' => 1,
            'codec_name' => 'aac',
            'codec_type' => 'audio',
            'channels' => 6,
            'sample_rate' => '48000',
            'disposition' => ['default' => 1],
        ]],
        'format' => ['format_name' => 'matroska,webm', 'duration' => '123.456'],
    ], JSON_THROW_ON_ERROR);
}

function processingUpload(User $owner, string $root, int $size = 12): Upload
{
    $upload = Upload::factory()->for($owner)->create([
        'disk_id' => 'movies_a',
        'target_relative_path' => 'Test Movie (2026) [tmdbid-123]/Test Movie (2026) [tmdbid-123].mkv',
        'declared_size' => $size,
        'confirmed_offset' => 0,
    ]);

    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Processing->value,
        'confirmed_offset' => $size,
        'tus_resource_id' => $upload->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'processing_at' => now(),
    ]);

    $stagePath = $root.'/'.$upload->staging_relative_path;
    file_put_contents($stagePath, str_repeat('v', $size));
    file_put_contents($root.'/metadata/'.$upload->uuid.'.info', json_encode([
        'ID' => $upload->uuid,
        'Size' => $size,
        'SizeIsDeferred' => false,
        'MetaData' => ['upload_uuid' => $upload->uuid],
        'IsPartial' => false,
        'IsFinal' => false,
        'PartialUploads' => null,
        'Storage' => ['Path' => $stagePath],
    ], JSON_THROW_ON_ERROR));

    return $upload->refresh();
}

function processingClaim(Upload $upload, string $root): array
{
    $metadata = lstat($root.'/'.$upload->staging_relative_path);

    return [
        'version' => 1,
        'expected_size' => $upload->declared_size,
        'device_id' => $metadata['dev'],
        'container' => 'matroska',
        'duration_milliseconds' => 12_500,
        'video' => [['index' => 0, 'codec' => 'h264', 'width' => 1920, 'height' => 1080, 'language' => 'eng', 'disposition' => ['default' => true]]],
        'audio' => [['index' => 1, 'codec' => 'aac', 'channels' => 2, 'language' => 'eng', 'disposition' => ['default' => true]]],
        'snapshot' => ['format' => ['container' => 'matroska', 'duration_milliseconds' => 12_500]],
    ];
}

beforeEach(function () {
    $this->processingFilesystem = new Filesystem;
    $this->processingRoot = storage_path('framework/testing/processing-'.bin2hex(random_bytes(6)));
    $this->processingMetadata = $this->processingRoot.'/metadata';
    $this->processingFilesystem->makeDirectory($this->processingRoot.'/.media-upload-manager/incoming', 0750, true);
    $this->processingFilesystem->makeDirectory($this->processingMetadata, 0750, true);
    file_put_contents($this->processingRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_a'));
    configureProcessingTest($this->processingRoot, $this->processingMetadata);
    Http::fake([
        'http://127.0.0.1:1080/uploads/tus/*' => Http::response('', 200, [
            'Upload-Offset' => '12',
            'Upload-Length' => '12',
        ]),
    ]);
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result(output: processingProbeJson()));
});

afterEach(function () {
    $this->processingFilesystem->deleteDirectory($this->processingRoot);
});

it('validates, atomically links, commits exactly one current media file, and cleans only the info sidecar', function () {
    $upload = processingUpload(User::factory()->create(), $this->processingRoot);
    $stagePath = $this->processingRoot.'/'.$upload->staging_relative_path;
    $initialInode = lstat($stagePath)['ino'];
    file_put_contents($this->processingMetadata.'/'.$upload->uuid.'.lock', 'keep');

    app(FinalizeProcessedUpload::class)->process($upload);
    app(FinalizeProcessedUpload::class)->process($upload->refresh());

    $targetPath = $this->processingRoot.'/'.$upload->target_relative_path;
    $mediaFile = MediaFile::query()->sole();

    expect($upload->refresh()->status)->toBe(UploadStatus::Completed)
        ->and($upload->expires_at)->toBeNull()
        ->and($upload->processing_claim)->not->toBeNull()
        ->and($upload->finalization_started_at)->not->toBeNull()
        ->and($upload->mediaItem->refresh()->current_media_file_id)->toBe($mediaFile->id)
        ->and($mediaFile->container)->toBe('matroska')
        ->and($mediaFile->duration_milliseconds)->toBe(123_456)
        ->and(file_exists($stagePath))->toBeFalse()
        ->and(file_exists($targetPath))->toBeTrue()
        ->and(lstat($targetPath)['ino'])->toBe($initialInode)
        ->and(file_exists($this->processingMetadata.'/'.$upload->uuid.'.info'))->toBeFalse()
        ->and(file_get_contents($this->processingMetadata.'/'.$upload->uuid.'.lock'))->toBe('keep')
        ->and(MediaFile::query()->count())->toBe(1);

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[0] === 'ffprobe'
        && end($process->command) === $stagePath);
});

it('recovers after an exclusive link or staging unlink crash', function (string $crashPoint) {
    $upload = processingUpload(User::factory()->create(), $this->processingRoot);
    $stagePath = $this->processingRoot.'/'.$upload->staging_relative_path;
    $targetPath = $this->processingRoot.'/'.$upload->target_relative_path;
    $this->processingFilesystem->makeDirectory(dirname($targetPath), 0750, true);
    link($stagePath, $targetPath);
    $claim = processingClaim($upload, $this->processingRoot);
    Upload::query()->whereKey($upload)->update([
        'processing_claim' => json_encode($claim, JSON_THROW_ON_ERROR),
        'finalization_started_at' => now(),
    ]);

    if ($crashPoint === 'after unlink') {
        unlink($stagePath);
    }

    app(FinalizeProcessedUpload::class)->process($upload->refresh());

    expect($upload->refresh()->status)->toBe(UploadStatus::Completed)
        ->and(file_exists($stagePath))->toBeFalse()
        ->and(file_exists($targetPath))->toBeTrue()
        ->and(MediaFile::query()->count())->toBe(1);

    Process::assertNothingRan();
})->with(['after link', 'after unlink']);

it('fails closed without changing a different-inode target', function () {
    $upload = processingUpload(User::factory()->create(), $this->processingRoot);
    $targetPath = $this->processingRoot.'/'.$upload->target_relative_path;
    $this->processingFilesystem->makeDirectory(dirname($targetPath), 0750, true);
    file_put_contents($targetPath, 'other-target');
    $before = file_get_contents($targetPath);
    $claim = processingClaim($upload, $this->processingRoot);
    Upload::query()->whereKey($upload)->update([
        'processing_claim' => json_encode($claim, JSON_THROW_ON_ERROR),
        'finalization_started_at' => now(),
    ]);

    expect(fn () => app(FinalizeProcessedUpload::class)->process($upload->refresh()))
        ->toThrow(UploadProcessingException::class)
        ->and(file_get_contents($targetPath))->toBe($before)
        ->and(MediaFile::query()->count())->toBe(0);
});

it('retains an invalid staged file with a safe visible failure', function () {
    $owner = User::factory()->create();
    $upload = processingUpload($owner, $this->processingRoot);
    Process::fake(fn () => Process::result(errorOutput: '/private/secret/path', exitCode: 1));
    $job = new ProcessCompletedUpload($upload->id);

    $job->handle(app(FinalizeProcessedUpload::class), app(TransitionUploadStatus::class));

    $stagePath = $this->processingRoot.'/'.$upload->staging_relative_path;
    $response = $this->actingAs($owner)
        ->getJson(route('uploads.show', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.media_item_id', $upload->media_item_id)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failure.code', 'media_probe_failed')
        ->assertJsonPath('data.failure.can_retry', false);

    expect(file_exists($stagePath))->toBeTrue()
        ->and($response->getContent())->not->toContain('/private/secret/path');
});

it('queues existing processing uploads once per upload', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $first = processingUpload($owner, $this->processingRoot);
    $second = processingUpload($owner, $this->processingRoot);

    $this->artisan('uploads:recover-processing')->assertSuccessful();

    Queue::assertPushed(ProcessCompletedUpload::class, 2);
    Queue::assertPushed(fn (ProcessCompletedUpload $job): bool => $job->uniqueId() === (string) $first->id);
    Queue::assertPushed(fn (ProcessCompletedUpload $job): bool => $job->uniqueId() === (string) $second->id);
});

it('allows an owner to retry recoverable failures and discard a retained failed stage', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $retry = processingUpload($owner, $this->processingRoot);
    Upload::query()->whereKey($retry)->update([
        'status' => UploadStatus::Failed->value,
        'error_code' => 'media_disk_unavailable',
        'error_detail' => 'The selected media disk is temporarily unavailable.',
        'failed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson(route('uploads.processing.retry', $retry))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'processing');
    Queue::assertPushed(ProcessCompletedUpload::class, 1);

    Upload::query()->whereKey($retry)->update([
        'status' => UploadStatus::Failed->value,
        'error_code' => 'media_video_missing',
        'error_detail' => 'No valid video stream.',
        'failed_at' => now(),
    ]);

    $stagePath = $this->processingRoot.'/'.$retry->staging_relative_path;
    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', $retry))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    expect(file_exists($stagePath))->toBeFalse();
});

it('serializes safe failure and finalized technical metadata without absolute paths or probe claims', function () {
    $owner = User::factory()->create();
    $upload = processingUpload($owner, $this->processingRoot);
    app(FinalizeProcessedUpload::class)->process($upload);

    $content = $this->actingAs($owner)
        ->getJson(route('uploads.show', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.media_item_id', $upload->media_item_id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.finalized.container', 'matroska')
        ->assertJsonPath('data.finalized.video.0.width', 3840)
        ->getContent();

    expect($content)->not->toContain($this->processingRoot)
        ->not->toContain('processing_claim')
        ->not->toContain('stderr');
});
