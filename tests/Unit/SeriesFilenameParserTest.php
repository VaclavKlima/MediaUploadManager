<?php

use App\Support\Series\SeriesFilenameParser;

it('parses exactly one regular or special episode identity', function (string $filename, int $season, int $episode, string $identity) {
    $parsed = (new SeriesFilenameParser)->parse($filename);

    expect($parsed->accepted())->toBeTrue()
        ->and($parsed->filename)->toBe(Normalizer::normalize($filename, Normalizer::FORM_C))
        ->and($parsed->seasonNumber)->toBe($season)
        ->and($parsed->episodeNumber)->toBe($episode)
        ->and($parsed->identity())->toBe($identity);
})->with([
    ['Show.S01E02.1080p.mkv', 1, 2, 'S01E02'],
    ['Show s0e105 Special.mp4', 0, 105, 'S00E105'],
    ['Show.S123E456.m4v', 123, 456, 'S123E456'],
    ['Gargantia On The Verdurous Planet - S00E17 - The Ocean Routes Stretch Into The Distance, Pt. 1.mkv', 0, 17, 'S00E17'],
    ['Gargantia On The Verdurous Planet - S00E18 - The Ocean Routes Stretch Into The Distance, Pt. 2.mkv', 0, 18, 'S00E18'],
    ['Show - S01E02 - A Long Journey, Part 1.mkv', 1, 2, 'S01E02'],
    ['Show - s01e03 - A Long Journey, pT.2.MKV', 1, 3, 'S01E03'],
    ['Show - S01E04 - A Long Journey, PART. 2.mp4', 1, 4, 'S01E04'],
    ["Show - S01E05 - Cafe\u{0301}, Pt. 2.mkv", 1, 5, 'S01E05'],
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
    ['Show.S01E01.pt2.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - A Long Journey Part 2.mkv', 'multipart_or_multiple_version'],
    ['Show.S01E01.A Long Journey, Pt. 2.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - , Pt. 2.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - ... , Pt. 2.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - A Long Journey, Pt. 0.mkv', 'multipart_or_multiple_version'],
    ['Show.Part.2 - S01E01 - A Long Journey, Pt. 1.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - Part 2 of A Long Journey, Pt. 1.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - A Long Journey, Pt. 1.Part.2.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - A Long Journey, Pt. 1, Pt. 2.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01 - Part 2, A Long Journey, Pt. 1.mkv', 'multipart_or_multiple_version'],
    ['Show,Part.2 - S01E01 - A Long Journey, Pt. 1.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01E02 - A Long Journey, Pt. 1.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01-E02 - A Long Journey, Pt. 1.mkv', 'multipart_or_multiple_version'],
    ['Show - S01E01.S01E02 - A Long Journey, Pt. 1.mkv', 'multi_episode'],
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
