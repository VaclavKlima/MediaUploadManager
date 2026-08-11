<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\UploadConfiguration;
use App\ValueObjects\TokenHash;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

function configureTusTransportTest(string $root): void
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
function transportUpload(User $user, array $overrides = []): array
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
        ...$overrides,
    ]);

    return [$upload, $token];
}

beforeEach(function () {
    $this->tusFilesystem = new Filesystem;
    $this->tusRoot = storage_path('framework/testing/tus-'.bin2hex(random_bytes(6)));
    $this->tusFilesystem->makeDirectory($this->tusRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->tusRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_a'));
    configureTusTransportTest($this->tusRoot);
});

afterEach(function () {
    $this->tusFilesystem->deleteDirectory($this->tusRoot);
});

it('authorizes only the bearer token ability, upload identity, length, and active disk', function () {
    [$upload, $token] = transportUpload(User::factory()->create());
    $metadata = 'upload_uuid '.base64_encode($upload->uuid);

    $this->get('/internal/tus/authorize', [
        'Authorization' => 'Bearer '.$token,
        'X-Original-Method' => 'POST',
        'X-Original-Uri' => '/uploads/tus/',
        'Tus-Resumable' => '1.0.0',
        'Upload-Length' => '100',
        'Upload-Metadata' => $metadata,
    ])->assertNoContent();

    $this->get('/internal/tus/authorize', [
        'Authorization' => 'Bearer '.$token,
        'X-Original-Method' => 'POST',
        'X-Original-Uri' => '/uploads/tus/',
        'Tus-Resumable' => '1.0.0',
        'Upload-Length' => '101',
        'Upload-Metadata' => $metadata,
    ])->assertForbidden()->assertContent('');

    $upload->update(['token_expires_at' => now()->subSecond()]);

    $this->get('/internal/tus/authorize', [
        'Authorization' => 'Bearer '.$token,
        'X-Original-Method' => 'HEAD',
        'X-Original-Uri' => '/uploads/tus/'.$upload->uuid,
        'Tus-Resumable' => '1.0.0',
    ])->assertUnauthorized()->assertContent('');
});

it('lists only the owners active sessions without secrets or host paths', function () {
    $owner = User::factory()->create();
    [$owned] = transportUpload($owner);
    transportUpload(User::factory()->create());

    $response = $this->actingAs($owner)
        ->getJson(route('uploads.resumable'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $owned->uuid)
        ->assertJsonPath('data.0.media_item_id', $owned->media_item_id)
        ->assertJsonPath('meta.fingerprint_window_bytes', 1_048_576);

    expect($response->getContent())
        ->not->toContain('token_hash')
        ->not->toContain('token_expires_at')
        ->not->toContain('processing_claim')
        ->not->toContain('hook_secret')
        ->not->toContain($this->tusRoot);
});

it('requires an exact fingerprint and rotates authorization for owners and administrators', function (string $actorType) {
    $owner = User::factory()->create();
    [$upload, $oldToken] = transportUpload($owner);
    $actor = $actorType === 'owner'
        ? $owner
        : User::factory()->administrator()->create();
    $payload = [
        'filename' => $upload->original_filename,
        'declared_size' => $upload->declared_size,
        'last_modified_milliseconds' => $upload->last_modified_milliseconds,
        'fingerprint_first_sha256' => $upload->fingerprint_first_sha256,
        'fingerprint_last_sha256' => $upload->fingerprint_last_sha256,
    ];

    $response = $this->actingAs($actor)
        ->postJson(route('uploads.authorization', $upload), $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.endpoint', '/uploads/tus/')
        ->assertJsonPath('data.resource_url', null)
        ->assertJsonPath('data.transport.chunk_size_bytes', 67_108_864);

    $newToken = $response->json('data.authorization.token');
    $upload->refresh();

    expect($newToken)->not->toBe($oldToken)
        ->and(TokenHash::fromHash((string) $upload->token_hash)->matches($newToken))->toBeTrue()
        ->and(TokenHash::fromHash((string) $upload->token_hash)->matches($oldToken))->toBeFalse();

    $this->actingAs($actor)
        ->postJson(route('uploads.authorization', $upload), [
            ...$payload,
            'fingerprint_last_sha256' => hash('sha256', 'changed'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'upload_fingerprint_mismatch');
})->with(['owner', 'administrator']);

it('pauses active uploads and revokes the write token', function () {
    $owner = User::factory()->create();
    [$upload] = transportUpload($owner);
    Upload::query()->whereKey($upload->id)->update([
        'status' => UploadStatus::Uploading->value,
        'uploading_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson(route('uploads.pause', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'paused');

    expect($upload->refresh()->token_hash)->toBeNull()
        ->and($upload->status)->toBe(UploadStatus::Paused);
});

it('terminates active tus resources internally before converging cancellation', function () {
    $owner = User::factory()->create();
    [$upload] = transportUpload($owner);
    $stagingPath = $this->tusRoot.'/'.$upload->staging_relative_path;
    file_put_contents($stagingPath, str_repeat('x', 40));
    Upload::query()->whereKey($upload->id)->update([
        'status' => UploadStatus::Uploading->value,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'tus_resource_id' => $upload->uuid,
        'confirmed_offset' => 40,
        'uploading_at' => now(),
    ]);

    Http::fake(function (ClientRequest $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Upload-Offset' => '40',
                'Upload-Length' => '100',
            ]);
        }

        return Http::response('', 204);
    });

    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/'.$upload->uuid));
    expect($upload->refresh()->status)->toBe(UploadStatus::Cancelled)
        ->and($upload->token_hash)->toBeNull();
});

it('does not allow another user to inspect or mutate a session', function () {
    [$upload] = transportUpload(User::factory()->create());
    $other = User::factory()->create();

    $this->actingAs($other)->getJson(route('uploads.show', $upload))->assertForbidden();
    $this->actingAs($other)->deleteJson(route('uploads.destroy', $upload))->assertForbidden();
});
