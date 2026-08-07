<?php

use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Media\TusMethodAbility;
use App\Support\Media\UploadConfiguration;

it('maps protected tus methods to their exact token abilities', function (string $method, ?string $ability) {
    expect(TusMethodAbility::for($method))->toBe($ability);
})->with([
    ['POST', 'tus:create'],
    ['head', 'tus:read'],
    ['PATCH', 'tus:write'],
    ['delete', 'tus:terminate'],
    ['OPTIONS', null],
    ['GET', null],
]);

it('parses the pinned browser transport settings', function () {
    $configuration = new UploadConfiguration([
        'tus_public_path' => '/uploads/tus/',
        'tus_internal_url' => 'http://tusd:1080/uploads/tus/',
        'hook_secret' => str_repeat('s', 32),
        'chunk_size_bytes' => '67108864',
        'retry_delays_milliseconds' => '0,3000,5000,10000,20000',
        'internal_connect_timeout_seconds' => '2',
        'internal_timeout_seconds' => '5',
        'token_ttl_seconds' => '900',
        'token_refresh_leeway_seconds' => '60',
        'inactivity_seconds' => '604800',
        'fingerprint_window_bytes' => '1048576',
        'tus_metadata_path' => '/var/lib/tusd',
        'ffprobe_binary' => '/usr/bin/ffprobe',
        'ffprobe_timeout_seconds' => '120',
        'ffprobe_max_output_bytes' => '1048576',
        'ffprobe_max_streams' => '64',
        'processing_job_timeout_seconds' => '180',
        'processing_job_unique_seconds' => '3600',
        'processing_job_backoff_seconds' => '15,60,180',
        'processing_poll_interval_milliseconds' => '1500',
        'queue_retry_after_seconds' => '240',
    ], production: true);

    expect($configuration->chunkSizeBytes)->toBe(67_108_864)
        ->and($configuration->retryDelaysMilliseconds)->toBe([0, 3000, 5000, 10000, 20000])
        ->and($configuration->hookSecretMatches(str_repeat('s', 32)))->toBeTrue()
        ->and($configuration->hookSecretMatches(str_repeat('x', 32)))->toBeFalse();
});

it('fails closed when production tus configuration is incomplete', function (array $overrides) {
    $configuration = [
        'tus_public_path' => '/uploads/tus/',
        'tus_internal_url' => 'http://tusd:1080/uploads/tus/',
        'hook_secret' => str_repeat('s', 32),
        'chunk_size_bytes' => '67108864',
        'retry_delays_milliseconds' => '0,3000,5000,10000,20000',
        'internal_connect_timeout_seconds' => '2',
        'internal_timeout_seconds' => '5',
        'token_ttl_seconds' => '900',
        'token_refresh_leeway_seconds' => '60',
        'inactivity_seconds' => '604800',
        'fingerprint_window_bytes' => '1048576',
        'tus_metadata_path' => '/var/lib/tusd',
        'ffprobe_binary' => '/usr/bin/ffprobe',
        'ffprobe_timeout_seconds' => '120',
        'ffprobe_max_output_bytes' => '1048576',
        'ffprobe_max_streams' => '64',
        'processing_job_timeout_seconds' => '180',
        'processing_job_unique_seconds' => '3600',
        'processing_job_backoff_seconds' => '15,60,180',
        'processing_poll_interval_milliseconds' => '1500',
        'queue_retry_after_seconds' => '240',
        ...$overrides,
    ];

    expect(fn () => new UploadConfiguration($configuration, production: true))
        ->toThrow(UploadAdmissionException::class);
})->with([
    'missing internal endpoint' => [['tus_internal_url' => null]],
    'short hook secret' => [['hook_secret' => 'short']],
    'unsafe internal endpoint' => [['tus_internal_url' => 'http://user:pass@tusd:1080/uploads/tus/']],
    'unbounded timeout' => [['internal_connect_timeout_seconds' => '10']],
    'invalid retries' => [['retry_delays_milliseconds' => '0,-1']],
    'missing ffprobe binary' => [['ffprobe_binary' => null]],
    'queue visibility shorter than job' => [['queue_retry_after_seconds' => '180']],
]);

it('pins an unbuffered private tusd and nginx transport', function () {
    $projectRoot = dirname(__DIR__, 2);
    $compose = file_get_contents($projectRoot.'/deploy/tus/docker-compose.fragment.yml');
    $publicNginx = file_get_contents($projectRoot.'/deploy/nginx/tus-public.location.conf');
    $hookNginx = file_get_contents($projectRoot.'/deploy/nginx/tus-hooks.server.conf.template');

    expect($compose)
        ->toContain('tusproject/tusd:v2.10.0')
        ->toContain('-disable-download')
        ->toContain('-disable-cors')
        ->toContain('-upload-dir=/var/lib/tusd')
        ->toContain('-hooks-http-backoff=1s')
        ->toContain('tus_metadata:/var/lib/tusd')
        ->toContain('${MEDIA_DISK_NAS_A_PATH}')
        ->not->toContain('-enable-termination')
        ->not->toContain('-disable-termination')
        ->not->toContain('ports:')
        ->and($publicNginx)
        ->toContain('auth_request /_mum_tus_authorize;')
        ->toContain('proxy_pass_request_body off;')
        ->toContain('proxy_request_buffering off;')
        ->toContain('proxy_buffering off;')
        ->toContain('proxy_set_header X-Forwarded-Proto $scheme;')
        ->toContain('proxy_set_header X-Original-Method $request_method;')
        ->toContain('location = /internal/tus/hooks')
        ->and($hookNginx)
        ->toContain('listen 8081;')
        ->toContain('X-Tus-Hook-Secret "${TUS_HOOK_SECRET}"');
});
