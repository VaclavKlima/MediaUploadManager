<?php

use App\Models\MediaItem;
use App\Support\Media\JellyfinMoviePathBuilder;

function moviePathMediaItem(string $title = 'The Matrix', ?int $year = 1999, int $tmdbId = 603): MediaItem
{
    return new MediaItem([
        'title' => $title,
        'release_year' => $year,
        'tmdb_id' => $tmdbId,
    ]);
}

it('builds the canonical path for every supported extension', function (string $extension) {
    $path = (new JellyfinMoviePathBuilder)->build(
        moviePathMediaItem(),
        'The.Matrix.1999.'.strtoupper($extension),
    );

    expect($path->directory)->toBe('The Matrix (1999) [tmdbid-603]')
        ->and($path->filename)->toBe("The Matrix (1999) [tmdbid-603].{$extension}")
        ->and($path->relativePath)->toBe("The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].{$extension}")
        ->and($path->extension)->toBe($extension);
})->with(array_combine(
    JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS,
    JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS,
));

it('rejects unsupported or missing extensions', function (string $filename) {
    (new JellyfinMoviePathBuilder)->build(moviePathMediaItem(), $filename);
})->with([
    'missing' => 'movie',
    'empty' => 'movie.',
    'subtitle' => 'movie.srt',
    'executable' => 'movie.exe',
    'double extension' => 'movie.mkv.gz',
])->throws(InvalidArgumentException::class);

it('normalizes Unicode to NFC and retains safe Unicode', function () {
    $decomposedTitle = "Am\u{0065}\u{0301}lie 東京";
    $path = (new JellyfinMoviePathBuilder)->build(moviePathMediaItem($decomposedTitle, 2001, 194), 'movie.MKV');

    expect($path->directory)->toBe('Amélie 東京 (2001) [tmdbid-194]')
        ->and(Normalizer::isNormalized($path->relativePath, Normalizer::FORM_C))->toBeTrue();
});

it('replaces forbidden and control characters and trims unsafe trailing characters', function () {
    $path = (new JellyfinMoviePathBuilder)->build(
        moviePathMediaItem("  A<>:\"/\\|?*\n\tB...  ", 2024, 42),
        'source.mkv',
    );

    expect($path->directory)->toBe('A B (2024) [tmdbid-42]')
        ->and($path->relativePath)->not->toMatch('/[<>:"\\\\|?*\x00-\x1F\x7F]/');
});

it('rejects empty sanitized titles and missing release years', function (string $title, ?int $year) {
    (new JellyfinMoviePathBuilder)->build(moviePathMediaItem($title, $year), 'movie.mkv');
})->with([
    'empty after sanitization' => ['<>:"/\\|?*', 1999],
    'missing year' => ['The Matrix', null],
    'invalid year' => ['The Matrix', 99],
])->throws(InvalidArgumentException::class);

it('rejects unsafe source basenames and traversal attempts', function (string $filename) {
    (new JellyfinMoviePathBuilder)->build(moviePathMediaItem(), $filename);
})->with([
    'parent traversal' => '../movie.mkv',
    'nested path' => 'folder/movie.mkv',
    'backslash path' => 'folder\\movie.mkv',
    'control' => "movie\nmkv",
    'nul' => "movie\0.mkv",
    'empty basename' => '.mkv',
    'overlong basename' => str_repeat('a', 252).'.mkv',
])->throws(InvalidArgumentException::class);

it('truncates long Unicode titles without breaking graphemes or identity suffixes', function () {
    $path = (new JellyfinMoviePathBuilder)->build(
        moviePathMediaItem(str_repeat('Family 👨‍👩‍👧‍👦 cinéma ', 40), 2026, 12_345_678),
        'movie.webm',
    );

    expect(strlen($path->directory))->toBeLessThanOrEqual(255)
        ->and(strlen($path->filename))->toBeLessThanOrEqual(255)
        ->and(mb_check_encoding($path->directory, 'UTF-8'))->toBeTrue()
        ->and($path->directory)->toEndWith(' (2026) [tmdbid-12345678]')
        ->and($path->filename)->toEndWith(' (2026) [tmdbid-12345678].webm')
        ->and(mb_strlen($path->relativePath))->toBeLessThanOrEqual(1024);
});

it('is deterministic and normalizes canonically equivalent titles to the same path', function () {
    $builder = new JellyfinMoviePathBuilder;
    $composed = $builder->build(moviePathMediaItem('Café'), 'source.MP4');
    $decomposed = $builder->build(moviePathMediaItem("Cafe\u{0301}"), 'source.mp4');

    expect($composed->toArray())->toBe($builder->build(moviePathMediaItem('Café'), 'source.MP4')->toArray())
        ->and($composed->toArray())->toBe($decomposed->toArray());
});
