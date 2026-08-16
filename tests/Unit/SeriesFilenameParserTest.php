<?php

use App\Support\Series\SeriesFilenameParser;

it('parses exactly one regular or special episode identity', function (string $filename, int $season, int $episode, string $identity) {
    $parsed = (new SeriesFilenameParser)->parse($filename);

    expect($parsed->accepted())->toBeTrue()
        ->and($parsed->seasonNumber)->toBe($season)
        ->and($parsed->episodeNumber)->toBe($episode)
        ->and($parsed->identity())->toBe($identity);
})->with([
    ['Show.S01E02.1080p.mkv', 1, 2, 'S01E02'],
    ['Show s0e105 Special.mp4', 0, 105, 'S00E105'],
    ['Show.S123E456.m4v', 123, 456, 'S123E456'],
]);

it('explains unsupported, extra, unresolved, multi-episode, and multipart inputs', function (string $filename, string $reason) {
    $parsed = (new SeriesFilenameParser)->parse($filename);

    expect($parsed->accepted())->toBeFalse()
        ->and($parsed->excludedReason)->toBe($reason);
})->with([
    ['Show.S01E01.srt', 'unsupported_video'],
    ['Show.Bonus.S01E01.mkv', 'known_extra'],
    ['Show.001.mkv', 'episode_identity_missing'],
    ['Show.S01E01.S01E02.mkv', 'multi_episode'],
    ['Show.S01E01.Part.2.mkv', 'multipart_or_multiple_version'],
]);

it('recognizes the Breaking Bad sample directory as four episodes across two seasons', function () {
    $sampleFiles = [
        'Breaking Bad/Season 01/Breaking.Bad.S01E01.Pilot.mkv',
        'Breaking Bad/Season 01/Breaking.Bad.S01E02.Cats.in.the.Bag.mkv',
        'Breaking Bad/Season 02/Breaking.Bad.S02E01.Seven.Thirty-Seven.mp4',
        'Breaking Bad/Season 02/Breaking.Bad.S02E02.Grilled.webm',
    ];

    $episodes = collect($sampleFiles)
        ->map(fn (string $path) => (new SeriesFilenameParser)->parse(basename($path)))
        ->filter(fn ($episode) => $episode->accepted());

    expect($episodes)->toHaveCount(4)
        ->and($episodes->pluck('seasonNumber')->unique()->sort()->values()->all())
        ->toBe([1, 2])
        ->and($episodes->map(fn ($episode) => $episode->identity())->all())
        ->toBe(['S01E01', 'S01E02', 'S02E01', 'S02E02']);
});
