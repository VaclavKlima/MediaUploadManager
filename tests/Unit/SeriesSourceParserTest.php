<?php

use App\Support\Tmdb\FilenameParser;
use App\Support\Tmdb\SeriesSourceParser;

it('infers a show title and year from episode filenames and complete show folders', function (string $source, string $title, ?int $year) {
    $parsed = (new SeriesSourceParser(new FilenameParser))->parse($source);

    expect($parsed->title)->toBe($title)
        ->and($parsed->year)->toBe($year);
})->with([
    ['Breaking.Bad.2008.S01E02.1080p.WEB-DL.x265.mkv', 'Breaking Bad', 2008],
    ['Breaking Bad (2008)', 'Breaking Bad', 2008],
    ['Amélie.S01E01.FRENCH.1080p.BluRay.mkv', 'Amélie', null],
]);

it('keeps subtitle and transliteration variants from the shared filename cleanup', function () {
    $parsed = (new SeriesSourceParser(new FilenameParser))
        ->parse('Amélie: Le fabuleux destin.2001.S01E01.mkv');

    expect($parsed->searchVariants)
        ->toContain('Amélie: Le fabuleux destin')
        ->toContain('Amélie')
        ->toContain('Amelie: Le fabuleux destin');
});
