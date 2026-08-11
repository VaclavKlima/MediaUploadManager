<?php

use App\Models\MediaFile;
use App\Support\Media\MovieTechnicalTagPresenter;

/**
 * @param  array<mixed>  $video
 * @param  array<mixed>  $audio
 * @return list<array{kind: string, label: string}>
 */
function technicalTags(array $video, array $audio = [], int $durationMilliseconds = 7_860_000): array
{
    $mediaFile = new MediaFile;
    $mediaFile->video_metadata = $video;
    $mediaFile->audio_metadata = $audio;
    $mediaFile->duration_milliseconds = $durationMilliseconds;

    return (new MovieTechnicalTagPresenter)->present($mediaFile);
}

it('builds at most four compact tags from the selected primary streams', function () {
    $tags = technicalTags([
        [
            'index' => 4,
            'codec' => 'av1',
            'width' => 2560,
            'dynamic_range' => 'hdr10_plus',
            'disposition' => ['default' => true],
        ],
        [
            'index' => 1,
            'codec' => 'h264',
            'width' => 1920,
            'dynamic_range' => 'sdr',
            'disposition' => ['default' => false],
        ],
    ], [
        [
            'index' => 8,
            'codec' => 'aac',
            'channels' => 2,
            'disposition' => ['default' => true],
        ],
        ['index' => 9, 'codec' => 'ac3', 'channels' => 6],
    ]);

    expect($tags)->toBe([
        ['kind' => 'quality', 'label' => '2K · HDR10+'],
        ['kind' => 'video', 'label' => 'AV1'],
        ['kind' => 'audio', 'label' => 'AAC 2.0 +1'],
        ['kind' => 'duration', 'label' => '2h 11m'],
    ]);
});

it('uses width tiers so cropped video keeps its expected quality class', function (int $width, string $expected) {
    expect(technicalTags([['codec' => 'hevc', 'width' => $width]])[0]['label'])->toBe($expected);
})->with([
    'cropped UHD' => [3840, '4K'],
    'DCI 2K' => [2048, '2K'],
    'cropped full HD' => [1920, '1080p'],
    'cropped HD' => [1280, '720p'],
]);

it('breaks ambiguous default streams by the lowest stream index', function () {
    $tags = technicalTags([
        ['index' => 3, 'codec' => 'av1', 'width' => 3840, 'disposition' => ['default' => true]],
        ['index' => 1, 'codec' => 'h264', 'width' => 1920, 'disposition' => ['default' => true]],
    ], [
        ['index' => 5, 'codec' => 'eac3', 'channels' => 8, 'disposition' => ['default' => true]],
        ['index' => 2, 'codec' => 'aac', 'channel_layout' => 'stereo', 'disposition' => ['default' => true]],
    ]);

    expect($tags)->toContain(
        ['kind' => 'quality', 'label' => '1080p'],
        ['kind' => 'video', 'label' => 'H.264'],
        ['kind' => 'audio', 'label' => 'AAC 2.0 +1'],
    );
});

it('labels known codecs and safely bounds legacy fallback values', function () {
    expect(technicalTags([
        ['codec' => 'hevc', 'width' => 3840, 'dynamic_range' => 'dolby_vision'],
    ], [
        ['codec' => 'eac3', 'channel_layout' => '5.1(side)'],
    ]))->toBe([
        ['kind' => 'quality', 'label' => '4K · Dolby Vision'],
        ['kind' => 'video', 'label' => 'HEVC'],
        ['kind' => 'audio', 'label' => 'Dolby Digital Plus 5.1'],
        ['kind' => 'duration', 'label' => '2h 11m'],
    ])->and(technicalTags([
        ['codec' => '<script>alert(1)</script>', 'width' => 1920],
    ], durationMilliseconds: 2_820_000))->toBe([
        ['kind' => 'quality', 'label' => '1080p'],
        ['kind' => 'duration', 'label' => '47m'],
    ]);
});

it('omits unknown and SDR labels while tolerating malformed legacy metadata', function () {
    expect(technicalTags([
        'codec' => 'vp9',
        'width' => 1920,
        'dynamic_range' => 'sdr',
    ], ['codec' => 'aac', 'channels' => 'six']))->toBe([
        ['kind' => 'quality', 'label' => '1080p'],
        ['kind' => 'video', 'label' => 'VP9'],
        ['kind' => 'audio', 'label' => 'AAC'],
        ['kind' => 'duration', 'label' => '2h 11m'],
    ])->and(technicalTags([
        ['codec' => [], 'width' => 'wide', 'dynamic_range' => '<unsafe>'],
        'not-a-stream',
    ], ['bad'], 0))->toBe([]);
});
