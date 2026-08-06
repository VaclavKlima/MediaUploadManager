<?php

namespace App\Support\Tmdb;

use App\Support\Tmdb\Data\MovieSearchResult;
use App\Support\Tmdb\Data\MovieSummary;
use Illuminate\Support\Str;

final class MovieRanker
{
    /**
     * @param  list<MovieSearchResult>  $searchResults
     * @return list<MovieSummary>
     */
    public function rank(?int $year, array $searchResults): array
    {
        /** @var array<int, array{movie: MovieSummary, relevance: float, similarity: float, query: int, position: int, hits: int}> $ranked */
        $ranked = [];

        foreach ($searchResults as $queryIndex => $searchResult) {
            foreach ($searchResult->movies as $position => $candidate) {
                $positionRelevance = max(0.0, 1 - ($position / 20));
                $queryRelevance = $searchResult->priority * $positionRelevance;
                $titleSimilarity = $this->titleSimilarity($searchResult->query, $candidate);
                $existing = $ranked[$candidate->tmdbId] ?? null;

                if ($existing === null) {
                    $ranked[$candidate->tmdbId] = [
                        'movie' => $candidate,
                        'relevance' => $queryRelevance,
                        'similarity' => $titleSimilarity,
                        'query' => $queryIndex,
                        'position' => $position,
                        'hits' => 1,
                    ];

                    continue;
                }

                $ranked[$candidate->tmdbId]['relevance'] = max($existing['relevance'], $queryRelevance);
                $ranked[$candidate->tmdbId]['similarity'] = max($existing['similarity'], $titleSimilarity);
                $ranked[$candidate->tmdbId]['hits']++;
            }
        }

        $ranked = array_values($ranked);

        usort($ranked, fn (array $first, array $second): int => $this->score($second, $year) <=> $this->score($first, $year)
            ?: $first['query'] <=> $second['query']
            ?: $first['position'] <=> $second['position']
            ?: $first['movie']->tmdbId <=> $second['movie']->tmdbId);

        return array_map(fn (array $candidate): MovieSummary => $candidate['movie'], $ranked);
    }

    public function titleSimilarity(string $query, MovieSummary $candidate): float
    {
        return max(
            $this->similarity($query, $candidate->title),
            $this->similarity($query, $candidate->originalTitle ?? ''),
        );
    }

    /** @param array{movie: MovieSummary, relevance: float, similarity: float, query: int, position: int, hits: int} $candidate */
    private function score(array $candidate, ?int $year): float
    {
        $yearScore = match (true) {
            $year === null || $candidate['movie']->releaseYear === null => 0.0,
            $year === $candidate['movie']->releaseYear => 0.35,
            abs($year - $candidate['movie']->releaseYear) === 1 => 0.15,
            default => -0.10,
        };
        $multipleQueryBonus = min(0.10, max(0, $candidate['hits'] - 1) * 0.05);

        return (0.60 * $candidate['relevance'])
            + (0.30 * $candidate['similarity'])
            + $yearScore
            + $multipleQueryBonus;
    }

    private function similarity(string $first, string $second): float
    {
        $normalizedFirst = $this->normalize($first);
        $normalizedSecond = $this->normalize($second);

        if ($normalizedFirst === '' || $normalizedSecond === '') {
            return 0.0;
        }

        if ($normalizedFirst === $normalizedSecond) {
            return 1.0;
        }

        $maximumLength = max(strlen($normalizedFirst), strlen($normalizedSecond));

        return max(0.0, 1 - (levenshtein($normalizedFirst, $normalizedSecond) / $maximumLength));
    }

    private function normalize(string $title): string
    {
        $normalized = Str::of($title)->transliterate()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();

        return $normalized->toString();
    }
}
