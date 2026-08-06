<?php

namespace App\Support\Tmdb;

use App\Support\Tmdb\Data\MovieSearchResult;
use App\Support\Tmdb\Data\MovieSummary;
use App\Support\Tmdb\Data\ParsedFilename;
use App\Support\Tmdb\Exceptions\MovieLookupException;

final class MovieSuggestionFinder
{
    private const MINIMUM_TITLE_SIMILARITY = 0.45;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly MovieRanker $movieRanker,
    ) {}

    /** @return list<MovieSummary> */
    public function find(ParsedFilename $filename): array
    {
        $primaryMovies = $this->tmdb->searchMovies($filename->title, $filename->year);
        $searchResults = [new MovieSearchResult($filename->title, 1.0, $primaryMovies)];

        if (! $this->isWeak($filename, $primaryMovies)) {
            return $this->movieRanker->rank($filename->year, $searchResults);
        }

        foreach ($this->fallbackQueries($filename) as $queryIndex => $fallback) {
            try {
                $movies = $this->tmdb->searchMovies($fallback['query'], $fallback['year']);
            } catch (MovieLookupException $exception) {
                if ($this->hasCandidates($searchResults)) {
                    break;
                }

                throw $exception;
            }

            $searchResults[] = new MovieSearchResult(
                $fallback['query'],
                max(0.70, 0.85 - ($queryIndex * 0.15)),
                $movies,
            );
        }

        return $this->movieRanker->rank($filename->year, $searchResults);
    }

    /** @param list<MovieSummary> $movies */
    private function isWeak(ParsedFilename $filename, array $movies): bool
    {
        if ($movies === []) {
            return true;
        }

        if ($filename->year !== null) {
            $knownYears = array_values(array_filter(
                array_map(fn (MovieSummary $movie): ?int => $movie->releaseYear, $movies),
                fn (?int $year): bool => $year !== null,
            ));

            if ($knownYears !== [] && ! array_any(
                $knownYears,
                fn (int $year): bool => abs($year - $filename->year) <= 1,
            )) {
                return true;
            }
        }

        $bestSimilarity = max(array_map(
            fn (MovieSummary $movie): float => $this->movieRanker->titleSimilarity($filename->title, $movie),
            $movies,
        ));

        return $bestSimilarity < self::MINIMUM_TITLE_SIMILARITY;
    }

    /** @return list<array{query: string, year: null}> */
    private function fallbackQueries(ParsedFilename $filename): array
    {
        $fallbacks = [];

        if ($filename->year !== null) {
            $fallbacks[] = ['query' => $filename->title, 'year' => null];
        }

        foreach (array_slice($filename->searchVariants, 1) as $variant) {
            $fallbacks[] = ['query' => $variant, 'year' => null];
        }

        return array_slice($fallbacks, 0, 2);
    }

    /** @param list<MovieSearchResult> $searchResults */
    private function hasCandidates(array $searchResults): bool
    {
        return array_any($searchResults, fn (MovieSearchResult $result): bool => $result->movies !== []);
    }
}
