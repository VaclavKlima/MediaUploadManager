<?php

use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use App\Support\Media\UploadConfiguration;
use App\ValueObjects\TokenHash;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function configureInactiveExpiryTest(string $root): void
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
    config()->set('upload.tus_internal_url', 'http://127.0.0.1:1080/uploads/tus/');
    config()->set('upload.hook_secret', str_repeat('e', 32));

    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

function inactiveUpload(UploadStatus $status = UploadStatus::Pending, int $offset = 0, bool $withResource = false): Upload
{
    $token = bin2hex(random_bytes(32));
    $upload = Upload::factory()->for(User::factory())->create([
        'disk_id' => 'movies_a',
        'declared_size' => 100,
        'confirmed_offset' => $offset,
        'token_hash' => TokenHash::fromPlaintext($token)->value,
        'token_expires_at' => now()->addMinutes(15),
        'last_activity_at' => now()->subDays(8),
        'expires_at' => now()->subMinute(),
    ]);

    if ($status !== UploadStatus::Pending || $withResource) {
        Upload::query()->whereKey($upload)->update([
            'status' => $status->value,
            'tus_resource_id' => $withResource ? $upload->uuid : null,
            'tus_creation_claimed_at' => $withResource ? now()->subDays(8) : null,
            'tus_created_at' => $withResource ? now()->subDays(8) : null,
            'uploading_at' => $status === UploadStatus::Pending ? null : now()->subDays(8),
            'paused_at' => $status === UploadStatus::Paused ? now()->subDays(8) : null,
            'processing_at' => $status === UploadStatus::Processing ? now()->subDays(8) : null,
        ]);
    }

    return $upload->refresh();
}

/** @return array<string, mixed> */
function inactiveExpiryHookPayload(string $type, Upload $upload, string $path, int $offset, ?string $marker = null): array
{
    $headers = $marker === null ? [] : ['X-Media-Upload-Expiry' => [$marker]];

    return [
        'Type' => $type,
        'Event' => [
            'Upload' => [
                'ID' => $upload->uuid,
                'Size' => $upload->declared_size,
                'SizeIsDeferred' => false,
                'Offset' => $offset,
                'MetaData' => ['upload_uuid' => $upload->uuid],
                'IsPartial' => false,
                'IsFinal' => false,
                'PartialUploads' => null,
                'Storage' => ['Type' => 'filestore', 'Path' => $path],
            ],
            'HTTPRequest' => [
                'Method' => 'DELETE',
                'URI' => '/uploads/tus/'.$upload->uuid,
                'Header' => $headers,
            ],
        ],
    ];
}

function callInactiveExpiryHook($test, array $payload)
{
    return $test->postJson(route('internal.tus.hooks'), $payload, [
        'X-Tus-Hook-Secret' => str_repeat('e', 32),
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-09 12:00:00');
    Queue::fake([ProcessCompletedUpload::class]);
    $this->expiryFilesystem = new Filesystem;
    $this->expiryRoot = storage_path('framework/testing/expiry-'.bin2hex(random_bytes(6)));
    $this->expiryFilesystem->makeDirectory($this->expiryRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->expiryRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('movies_a'));
    configureInactiveExpiryTest($this->expiryRoot);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    $this->expiryFilesystem->deleteDirectory($this->expiryRoot);
});

it('atomically expires a never-created due session and revokes its token once', function () {
    $upload = inactiveUpload();

    $this->artisan('uploads:expire-inactive')
        ->expectsOutputToContain('1 expired')
        ->assertSuccessful();
    $this->artisan('uploads:expire-inactive')
        ->expectsOutputToContain('Examined 0 due uploads')
        ->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Expired)
        ->and($upload->expired_at)->not->toBeNull()
        ->and($upload->token_hash)->toBeNull()
        ->and($upload->token_abilities)->toBeNull()
        ->and($upload->token_expires_at)->toBeNull();
});

it('requests marked termination only after partial state and disk identity agree', function () {
    $upload = inactiveUpload(UploadStatus::Uploading, 40, withResource: true);
    $path = $this->expiryRoot.'/'.$upload->staging_relative_path;
    file_put_contents($path, str_repeat('x', 40));
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Upload-Offset' => '40', 'Upload-Length' => '100'])
        : Http::response('', 204));

    $this->artisan('uploads:expire-inactive')
        ->expectsOutputToContain('1 termination requests')
        ->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Uploading);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->hasHeader('X-Media-Upload-Expiry', str_repeat('e', 32)));
});

it('refreshes discovered activity and reconciles complete uploads to processing', function () {
    $progressed = inactiveUpload(UploadStatus::Uploading, 40, withResource: true);
    $progressedPath = $this->expiryRoot.'/'.$progressed->staging_relative_path;
    file_put_contents($progressedPath, str_repeat('x', 50));

    Http::fake([
        'http://127.0.0.1:1080/uploads/tus/'.$progressed->uuid => Http::response('', 200, [
            'Upload-Offset' => '50',
            'Upload-Length' => '100',
        ]),
    ]);

    $this->artisan('uploads:expire-inactive')->assertSuccessful();

    expect($progressed->refresh()->confirmed_offset)->toBe(50)
        ->and($progressed->expires_at?->isFuture())->toBeTrue()
        ->and($progressed->status)->toBe(UploadStatus::Uploading);

    $complete = inactiveUpload(UploadStatus::Uploading, 90, withResource: true);
    $completePath = $this->expiryRoot.'/'.$complete->staging_relative_path;
    file_put_contents($completePath, str_repeat('x', 100));
    Http::fake([
        'http://127.0.0.1:1080/uploads/tus/'.$complete->uuid => Http::response('', 200, [
            'Upload-Offset' => '100',
            'Upload-Length' => '100',
        ]),
    ]);

    $this->artisan('uploads:expire-inactive')->assertSuccessful();

    expect($complete->refresh()->status)->toBe(UploadStatus::Processing)
        ->and($complete->confirmed_offset)->toBe(100);
    Queue::assertPushed(ProcessCompletedUpload::class, 1);
});

it('preserves resources when transport or physical state is uncertain and never expires processing', function (string $case) {
    $upload = inactiveUpload(UploadStatus::Uploading, 40, withResource: true);
    $path = $this->expiryRoot.'/'.$upload->staging_relative_path;
    file_put_contents($path, str_repeat('x', $case === 'file mismatch' ? 39 : 40));

    if ($case === 'transport unavailable') {
        Http::fake(['*' => Http::failedConnection()]);
    } else {
        Http::fake(['*' => Http::response('', 200, [
            'Upload-Offset' => $case === 'offset regression' ? '30' : '40',
            'Upload-Length' => $case === 'length mismatch' ? '101' : '100',
        ])]);
    }

    $this->artisan('uploads:expire-inactive')
        ->expectsOutputToContain('1 deferred')
        ->doesntExpectOutputToContain($this->expiryRoot)
        ->assertSuccessful();

    expect($upload->refresh()->status)->toBe(UploadStatus::Uploading)
        ->and($upload->token_hash)->not->toBeNull();

    $processing = inactiveUpload(UploadStatus::Processing, 100, withResource: true);
    $this->artisan('uploads:expire-inactive')->assertSuccessful();
    expect($processing->refresh()->status)->toBe(UploadStatus::Processing);
})->with(['transport unavailable', 'length mismatch', 'offset regression', 'file mismatch']);

it('distinguishes marked system expiry from cancellation and ignores forged markers', function () {
    $expiring = inactiveUpload(UploadStatus::Uploading, 40, withResource: true);
    $expiringPath = $this->expiryRoot.'/'.$expiring->staging_relative_path;
    file_put_contents($expiringPath, str_repeat('x', 40));
    $marker = str_repeat('e', 32);

    callInactiveExpiryHook($this, inactiveExpiryHookPayload('pre-terminate', $expiring, $expiringPath, 40, $marker))
        ->assertSuccessful()
        ->assertJsonMissing(['RejectTermination' => true]);
    callInactiveExpiryHook($this, inactiveExpiryHookPayload('post-terminate', $expiring, $expiringPath, 40, $marker))
        ->assertSuccessful();
    callInactiveExpiryHook($this, inactiveExpiryHookPayload('post-terminate', $expiring, $expiringPath, 40, $marker))
        ->assertSuccessful();

    expect($expiring->refresh()->status)->toBe(UploadStatus::Expired);

    $cancelled = inactiveUpload(UploadStatus::Uploading, 20, withResource: true);
    $cancelledPath = $this->expiryRoot.'/'.$cancelled->staging_relative_path;
    file_put_contents($cancelledPath, str_repeat('x', 20));

    callInactiveExpiryHook($this, inactiveExpiryHookPayload('pre-terminate', $cancelled, $cancelledPath, 20, 'forged'))
        ->assertSuccessful();
    callInactiveExpiryHook($this, inactiveExpiryHookPayload('post-terminate', $cancelled, $cancelledPath, 20, 'forged'))
        ->assertSuccessful();

    expect($cancelled->refresh()->status)->toBe(UploadStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull();
});

it('rejects marked expiry when activity changed before the termination hook', function () {
    $upload = inactiveUpload(UploadStatus::Uploading, 40, withResource: true);
    $path = $this->expiryRoot.'/'.$upload->staging_relative_path;
    file_put_contents($path, str_repeat('x', 50));
    Upload::query()->whereKey($upload)->update([
        'confirmed_offset' => 50,
        'last_activity_at' => now(),
        'expires_at' => now()->addWeek(),
    ]);

    callInactiveExpiryHook(
        $this,
        inactiveExpiryHookPayload('pre-terminate', $upload->refresh(), $path, 40, str_repeat('e', 32)),
    )
        ->assertSuccessful()
        ->assertJsonPath('RejectTermination', true);

    expect($upload->refresh()->status)->toBe(UploadStatus::Uploading)
        ->and($upload->confirmed_offset)->toBe(50);
});

it('strips the internal expiry marker from the public tus proxy', function () {
    $configuration = file_get_contents(base_path('deploy/nginx/tus-public.location.conf'));

    expect($configuration)
        ->toContain('proxy_set_header X-Media-Upload-Expiry "";')
        ->toContain('proxy_request_buffering off;')
        ->toContain('client_max_body_size 65m;');
});
