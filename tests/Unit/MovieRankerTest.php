<?php

use App\Support\Tmdb\Data\MovieSearchResult;
use App\Support\Tmdb\Data\MovieSummary;
use App\Support\Tmdb\MovieRanker;

function rankedMovie(int $id, string $title, ?string $originalTitle, ?int $year): MovieSummary
{
    return new MovieSummary($id, $title, $originalTitle, null, $year, null, null, 'en');
}

function rankedSearch(string $query, array $movies, float $priority = 1.0): MovieSearchResult
{
    return new MovieSearchResult($query, $priority, $movies);
}

it('ranks exact normalized titles ahead of weaker matches', function () {
    $ranked = new MovieRanker()->rank(null, [rankedSearch('The Matrix', [
        rankedMovie(1, 'Matrix: Generations', null, 2023),
        rankedMovie(2, 'The Matrix', 'The Matrix', 1999),
    ])]);

    expect(array_column($ranked, 'tmdbId'))->toBe([2, 1]);
});

it('uses original titles and exact or near year bonuses', function () {
    $ranked = new MovieRanker()->rank(2001, [rankedSearch('Le fabuleux destin d Amelie Poulain', [
        rankedMovie(1, 'Amélie', 'Le Fabuleux Destin d’Amélie Poulain', 2003),
        rankedMovie(2, 'Amélie', 'Le Fabuleux Destin d’Amélie Poulain', 2002),
        rankedMovie(3, 'Amélie', 'Le Fabuleux Destin d’Amélie Poulain', 2001),
    ])]);

    expect(array_column($ranked, 'tmdbId'))->toBe([3, 2, 1]);
});

it('retains TMDB order as a deterministic tie breaker', function () {
    $ranked = new MovieRanker()->rank(2021, [rankedSearch('Dune', [
        rankedMovie(9, 'Dune', 'Dune', 2021),
        rankedMovie(4, 'Dune', 'Dune', 2021),
    ])]);

    expect(array_column($ranked, 'tmdbId'))->toBe([9, 4]);
});

it('returns an empty list for empty candidates', function () {
    expect(new MovieRanker()->rank(2020, []))->toBe([]);
});

it('uses localized and original multilingual titles for similarity', function () {
    $ranked = new MovieRanker()->rank(null, [rankedSearch('Sen to Chihiro no kamikakushi', [
        rankedMovie(1, 'Unrelated localized title', 'Sen to Chihiro no kamikakushi', 2001),
        rankedMovie(2, 'Sen to Chihiro sequel', null, 2025),
    ])]);

    expect(array_column($ranked, 'tmdbId'))->toBe([1, 2]);
});

it('deduplicates candidates and rewards hits across searches', function () {
    $multiHit = rankedMovie(10, 'Amélie', 'Le Fabuleux Destin d’Amélie Poulain', 2001);
    $ranked = new MovieRanker()->rank(null, [
        rankedSearch('Amélie', [rankedMovie(20, 'Amélie', 'Amélie', 2001), $multiHit]),
        rankedSearch('Amelie', [$multiHit], 0.85),
    ]);

    expect(array_column($ranked, 'tmdbId'))->toBe([10, 20])
        ->and(array_count_values(array_column($ranked, 'tmdbId'))[10])->toBe(1);
});

it('applies exact adjacent and mismatched year adjustments', function () {
    $ranked = new MovieRanker()->rank(2020, [rankedSearch('Dune', [
        rankedMovie(1, 'Dune', 'Dune', 1984),
        rankedMovie(2, 'Dune', 'Dune', 2021),
        rankedMovie(3, 'Dune', 'Dune', 2020),
    ])]);

    expect(array_column($ranked, 'tmdbId'))->toBe([3, 2, 1]);
});

it('uses the earliest query before position and ID for stable ties', function () {
    $ranked = new MovieRanker()->rank(null, [
        rankedSearch('Unknown', [rankedMovie(9, 'Different', null, null)]),
        rankedSearch('Unknown', [rankedMovie(4, 'Different', null, null)]),
    ]);

    expect(array_column($ranked, 'tmdbId'))->toBe([9, 4]);
});
