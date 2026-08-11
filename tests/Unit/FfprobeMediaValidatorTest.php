<?php

use App\Support\Media\Exceptions\UploadProcessingException;
use App\Support\Media\FfprobeMediaValidator;
use App\Support\Media\UploadConfiguration;

function probeConfiguration(array $overrides = []): UploadConfiguration
{
    return new UploadConfiguration([
        'tus_public_path' => '/uploads/tus/',
        'tus_internal_url' => 'http://127.0.0.1:1080/uploads/tus/',
        'hook_secret' => str_repeat('h', 32),
        'chunk_size_bytes' => 67_108_864,
        'retry_delays_milliseconds' => [0, 3000, 5000, 10000, 20000],
        'internal_connect_timeout_seconds' => 2,
        'internal_timeout_seconds' => 5,
        'token_ttl_seconds' => 900,
        'token_refresh_leeway_seconds' => 60,
        'inactivity_seconds' => 604_800,
        'fingerprint_window_bytes' => 1_048_576,
        'tus_metadata_path' => '/tmp/mum-tus-metadata',
        'ffprobe_binary' => '/opt/homebrew/bin/ffprobe',
        'ffprobe_timeout_seconds' => 120,
        'ffprobe_max_output_bytes' => 1_048_576,
        'ffprobe_max_streams' => 64,
        'processing_job_timeout_seconds' => 180,
        'processing_job_unique_seconds' => 3600,
        'processing_job_backoff_seconds' => [15, 60, 180],
        'processing_poll_interval_milliseconds' => 1500,
        ...$overrides,
    ]);
}

function validProbeJson(array $streams = []): string
{
    return json_encode([
        'streams' => $streams ?: [[
            'index' => 0,
            'codec_name' => 'hevc',
            'codec_type' => 'video',
            'width' => 3840,
            'height' => 2160,
            'color_transfer' => 'smpte2084',
            'tags' => ['language' => 'ENG'],
            'disposition' => ['default' => 1, 'forced' => 0],
        ], [
            'index' => 1,
            'codec_name' => 'aac',
            'codec_type' => 'audio',
            'channels' => 6,
            'channel_layout' => '5.1',
            'sample_rate' => '48000',
            'tags' => ['language' => 'ces'],
            'disposition' => ['default' => 1],
        ]],
        'format' => [
            'format_name' => 'matroska,webm',
            'duration' => '123.456000',
        ],
    ], JSON_THROW_ON_ERROR);
}

it('constructs a shell-free bounded ffprobe command and normalizes technical metadata', function () {
    $validator = new FfprobeMediaValidator(probeConfiguration());
    $path = '/private/stage/movie.part';
    $result = $validator->parse(validProbeJson());

    expect($validator->command($path))->toBe([
        '/opt/homebrew/bin/ffprobe',
        '-v',
        'error',
        '-show_entries',
        'format=format_name,duration:stream=index,codec_type,codec_name,width,height,color_transfer,channels,channel_layout,sample_rate:stream_tags=language:stream_disposition=default,forced,hearing_impaired,visual_impaired,comment,original,dub:stream_side_data=side_data_type',
        '-of',
        'json',
        $path,
    ])->and($result['container'])->toBe('matroska')
        ->and($result['duration_milliseconds'])->toBe(123_456)
        ->and($result['video'][0])->toMatchArray([
            'codec' => 'hevc',
            'width' => 3840,
            'height' => 2160,
            'dynamic_range' => 'hdr10',
            'language' => 'eng',
        ])->and($result['audio'][0])->toMatchArray([
            'codec' => 'aac',
            'channels' => 6,
            'channel_layout' => '5.1',
            'sample_rate' => 48000,
            'language' => 'ces',
        ])->and(json_encode($result))->not->toContain('/private/stage');
});

it('rejects invalid JSON, nonpositive duration, audio-only media, and stream overflow', function (string $fixture, string $code) {
    $configuration = probeConfiguration(['ffprobe_max_streams' => 2]);
    $validator = new FfprobeMediaValidator($configuration);

    $json = match ($fixture) {
        'json' => '{broken',
        'duration' => json_encode([
            'streams' => [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 10, 'height' => 10]],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '0'],
        ], JSON_THROW_ON_ERROR),
        'audio' => validProbeJson([['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'aac']]),
        default => validProbeJson([
            ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 10, 'height' => 10],
            ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
        ]),
    };

    try {
        $validator->parse($json);
    } catch (UploadProcessingException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->retryable)->toBeFalse();

        return;
    }

    test()->fail('Expected unsafe probe metadata to be rejected.');
})->with([
    'invalid JSON' => ['json', 'media_probe_invalid_json'],
    'zero duration' => ['duration', 'media_duration_invalid'],
    'audio only' => ['audio', 'media_video_missing'],
    'too many streams' => ['streams', 'media_stream_count_invalid'],
]);

it('rejects output beyond the configured byte bound', function () {
    $validator = new FfprobeMediaValidator(probeConfiguration(['ffprobe_max_output_bytes' => 32]));

    $validator->parse(validProbeJson());
})->throws(UploadProcessingException::class);

it('classifies bounded dynamic range metadata with the most specific signal first', function (
    array $metadata,
    string $expected,
) {
    $result = (new FfprobeMediaValidator(probeConfiguration()))->parse(validProbeJson([[
        'index' => 0,
        'codec_name' => 'hevc',
        'codec_type' => 'video',
        'width' => 3840,
        'height' => 1600,
        ...$metadata,
    ]]));

    expect($result['video'][0]['dynamic_range'])->toBe($expected)
        ->and($result['snapshot']['streams'][0]['dynamic_range'])->toBe($expected)
        ->and($result['video'][0])->not->toHaveKeys(['color_transfer', 'side_data_list']);
})->with([
    'Dolby Vision takes precedence over an HDR10 base layer' => [[
        'color_transfer' => 'smpte2084',
        'side_data_list' => [
            ['side_data_type' => 'Mastering display metadata'],
            ['side_data_type' => 'DOVI configuration record'],
        ],
    ], 'dolby_vision'],
    'HDR10+ takes precedence over static HDR metadata' => [[
        'color_transfer' => 'smpte2084',
        'side_data_list' => [['side_data_type' => 'HDR Dynamic Metadata SMPTE2094-40']],
    ], 'hdr10_plus'],
    'HDR10 from PQ transfer' => [['color_transfer' => 'smpte2084'], 'hdr10'],
    'HDR10 from mastering metadata' => [[
        'side_data_list' => [['side_data_type' => 'Mastering display metadata']],
    ], 'hdr10'],
    'HLG' => [['color_transfer' => 'arib-std-b67'], 'hlg'],
    'SDR' => [['color_transfer' => 'bt709'], 'sdr'],
    'unknown without a reliable signal' => [[], 'unknown'],
    'unknown with malformed side data' => [[
        'side_data_list' => ['bad', ['side_data_type' => [
            'not',
            'text',
        ]]],
    ], 'unknown'],
]);
