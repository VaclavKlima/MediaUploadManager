<?php

use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\UploadConfiguration;
use App\ValueObjects\TokenHash;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function configureTusHookTest(string $root): void
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
    config()->set('upload', [
        'tus_public_path' => '/uploads/tus/',
        'tus_internal_url' => 'http://127.0.0.1:1080/uploads/tus/',
        'hook_secret' => str_repeat('h', 32),
        'chunk_size_bytes' => '67108864',
        'retry_delays_milliseconds' => '0,3000,5000,10000,20000',
        'internal_connect_timeout_seconds' => '2',
        'internal_timeout_seconds' => '5',
        'token_ttl_seconds' => '900',
        'token_refresh_leeway_seconds' => '60',
        'inactivity_seconds' => '604800',
        'fingerprint_window_bytes' => '1048576',
    ]);

    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array{Upload, string} */
function hookUpload(User $user): array
{
    $token = bin2hex(random_bytes(32));
    $upload = Upload::factory()->for($user)->create([
        'disk_id' => 'movies_a',
        'declared_size' => 100,
        'confirmed_offset' => 0,
        'original_filename' => 'Movie.mkv',
        'last_modified_milliseconds' => 1_754_000_000_000,
        'fingerprint_first_sha256' => hash('sha256', 'first'),
        'fingerprint_last_sha256' => hash('sha256', 'last'),
        'token_hash' => TokenHash::fromPlaintext($token)->value,
        'token_expires_at' => now()->addMinutes(15),
        'expires_at' => now()->addWeek(),
    ]);

    return [$upload, $token];
}

/**
 * @return array<string, mixed>
 */
function tusHookPayload(
    string $type,
    Upload $upload,
    int $offset = 0,
    ?string $storagePath = null,
): array {
    return [
        'Type' => $type,
        'Event' => [
            'Upload' => [
                'ID' => $type === 'pre-create' ? '' : $upload->uuid,
                'Size' => $upload->declared_size,
                'SizeIsDeferred' => false,
                'Offset' => $offset,
                'MetaData' => ['upload_uuid' => $upload->uuid],
                'IsPartial' => false,
                'IsFinal' => false,
                'PartialUploads' => null,
                'Storage' => $storagePath === null ? null : [
                    'Type' => 'filestore',
                    'Path' => $storagePath,
                    'InfoPath' => '/private/tusd/'.$upload->uuid.'.info',
                ],
            ],
            'HTTPRequest' => [
                'Method' => 'PATCH',
                'URI' => '/uploads/tus/'.$upload->uuid,
            ],
        ],
    ];
}

function callTusHook($test, array $payload)
{
    return $test->postJson(route('internal.tus.hooks'), $payload, [
        'X-Tus-Hook-Secret' => str_repeat('h', 32),
    ]);
}

beforeEach(function () {
    Queue::fake([ProcessCompletedUpload::class]);
    $this->tusFilesystem = new Filesystem;
    $this->tusRoot = storage_path('framework/testing/hooks-'.bin2hex(random_bytes(6)));
    $this->tusFilesystem->makeDirectory($this->tusRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents(
        $this->tusRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies_a'),
    );
    configureTusHookTest($this->tusRoot);
});

afterEach(function () {
    $this->tusFilesystem->deleteDirectory($this->tusRoot);
});

it('rejects missing or incorrect hook secrets before parsing payloads', function () {
    $this->postJson(route('internal.tus.hooks'), [])->assertForbidden();
    $this->postJson(route('internal.tus.hooks'), [], [
        'X-Tus-Hook-Secret' => str_repeat('x', 32),
    ])->assertForbidden();
});

it('claims creation once and returns only the trusted deterministic identity and staging path', function () {
    [$upload] = hookUpload(User::factory()->create());
    $payload = tusHookPayload('pre-create', $upload);

    $response = callTusHook($this, $payload)
        ->assertSuccessful()
        ->assertJsonPath('ChangeFileInfo.ID', $upload->uuid)
        ->assertJsonPath('ChangeFileInfo.MetaData.upload_uuid', $upload->uuid)
        ->assertJsonPath(
            'ChangeFileInfo.Storage.Path',
            $this->tusRoot.'/'.$upload->staging_relative_path,
        );

    $firstClaim = $upload->refresh()->tus_creation_claimed_at;
    callTusHook($this, $payload)->assertSuccessful();

    expect($firstClaim)->not->toBeNull()
        ->and($upload->refresh()->tus_creation_claimed_at?->equalTo($firstClaim))->toBeTrue()
        ->and($response->getContent())->not->toContain('token_hash');
});

it('rejects deferred lengths, concatenation state, and client controlled metadata', function () {
    [$upload] = hookUpload(User::factory()->create());
    $payload = tusHookPayload('pre-create', $upload);
    $payload['Event']['Upload']['SizeIsDeferred'] = true;
    $payload['Event']['Upload']['MetaData']['path'] = '/tmp/escape';

    callTusHook($this, $payload)
        ->assertSuccessful()
        ->assertJsonPath('RejectUpload', true)
        ->assertJsonPath('HTTPResponse.StatusCode', 409);

    expect($upload->refresh()->tus_creation_claimed_at)->toBeNull();
});

it('handles create progress and finish hooks monotonically without status regression', function () {
    [$upload] = hookUpload(User::factory()->create());
    $stagingPath = $this->tusRoot.'/'.$upload->staging_relative_path;
    file_put_contents($stagingPath, '');

    callTusHook($this, tusHookPayload('pre-create', $upload))->assertSuccessful();
    callTusHook($this, tusHookPayload('post-create', $upload, 0, $stagingPath))->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Uploading)
        ->and($upload->tus_resource_id)->toBe($upload->uuid)
        ->and($upload->tus_created_at)->not->toBeNull();

    file_put_contents($stagingPath, str_repeat('x', 50));
    callTusHook($this, tusHookPayload('post-receive', $upload, 50, $stagingPath))->assertSuccessful();
    callTusHook($this, tusHookPayload('post-receive', $upload, 25, $stagingPath))->assertSuccessful();

    expect($upload->refresh()->confirmed_offset)->toBe(50);

    file_put_contents($stagingPath, str_repeat('x', 100));
    Http::fake([
        'http://127.0.0.1:1080/uploads/tus/*' => Http::response('', 200, [
            'Upload-Offset' => '100',
            'Upload-Length' => '100',
        ]),
    ]);

    callTusHook($this, tusHookPayload('post-finish', $upload, 100, $stagingPath))->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Processing)
        ->and($upload->confirmed_offset)->toBe(100)
        ->and($upload->token_hash)->toBeNull()
        ->and($upload->processing_at)->not->toBeNull();

    Queue::assertPushed(ProcessCompletedUpload::class, 1);

    callTusHook($this, tusHookPayload('post-create', $upload, 0, $stagingPath))->assertSuccessful();
    callTusHook($this, tusHookPayload('post-receive', $upload, 80, $stagingPath))->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Processing)
        ->and($upload->confirmed_offset)->toBe(100);
});

it('does not let a stale receive hook wake an explicitly paused upload', function () {
    [$upload] = hookUpload(User::factory()->create());
    $stagingPath = $this->tusRoot.'/'.$upload->staging_relative_path;
    file_put_contents($stagingPath, str_repeat('x', 50));
    Upload::query()->whereKey($upload->id)->update([
        'status' => UploadStatus::Paused->value,
        'tus_resource_id' => $upload->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'confirmed_offset' => 50,
        'uploading_at' => now()->subMinute(),
        'paused_at' => now(),
    ]);
    $upload->refresh();

    callTusHook($this, tusHookPayload('post-receive', $upload, 50, $stagingPath))->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Paused)
        ->and($upload->confirmed_offset)->toBe(50);
});

it('rejects unsafe termination races and converges repeated termination hooks', function () {
    [$processing] = hookUpload(User::factory()->create());
    $processingPath = $this->tusRoot.'/'.$processing->staging_relative_path;
    file_put_contents($processingPath, str_repeat('x', 100));
    Upload::query()->whereKey($processing->id)->update([
        'status' => UploadStatus::Processing->value,
        'tus_resource_id' => $processing->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'confirmed_offset' => 100,
        'processing_at' => now(),
    ]);
    $processing->refresh();

    callTusHook($this, tusHookPayload('pre-terminate', $processing, 100, $processingPath))
        ->assertSuccessful()
        ->assertJsonPath('RejectTermination', true);

    [$finishing] = hookUpload(User::factory()->create());
    $finishingPath = $this->tusRoot.'/'.$finishing->staging_relative_path;
    file_put_contents($finishingPath, str_repeat('x', 100));
    Upload::query()->whereKey($finishing->id)->update([
        'status' => UploadStatus::Uploading->value,
        'tus_resource_id' => $finishing->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'confirmed_offset' => 80,
        'uploading_at' => now(),
    ]);
    $finishing->refresh();

    callTusHook($this, tusHookPayload('pre-terminate', $finishing, 100, $finishingPath))
        ->assertSuccessful()
        ->assertJsonPath('RejectTermination', true);

    [$active] = hookUpload(User::factory()->create());
    $activePath = $this->tusRoot.'/'.$active->staging_relative_path;
    file_put_contents($activePath, str_repeat('x', 40));
    Upload::query()->whereKey($active->id)->update([
        'status' => UploadStatus::Uploading->value,
        'tus_resource_id' => $active->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'confirmed_offset' => 40,
        'uploading_at' => now(),
    ]);
    $active->refresh();
    $payload = tusHookPayload('post-terminate', $active, 40, $activePath);

    callTusHook($this, $payload)->assertSuccessful();
    callTusHook($this, $payload)->assertSuccessful();

    expect($active->refresh()->status)->toBe(UploadStatus::Cancelled)
        ->and($active->token_hash)->toBeNull();
});

it('returns stable safe reconciliation errors without leaking storage roots', function () {
    [$upload] = hookUpload(User::factory()->create());
    $stagingPath = $this->tusRoot.'/'.$upload->staging_relative_path;
    file_put_contents($stagingPath, str_repeat('x', 20));
    Upload::query()->whereKey($upload->id)->update([
        'status' => UploadStatus::Uploading->value,
        'tus_resource_id' => $upload->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'confirmed_offset' => 20,
        'uploading_at' => now(),
    ]);
    Http::fake([
        '*' => Http::response('', 200, [
            'Upload-Offset' => '30',
            'Upload-Length' => '100',
        ]),
    ]);

    $response = $this->actingAs($upload->user)
        ->getJson(route('uploads.show', $upload))
        ->assertConflict()
        ->assertJsonPath('error', 'upload_state_inconsistent');

    expect($response->getContent())->not->toContain($this->tusRoot);
});
