<?php

namespace App\Support\Tmdb\Data;

final readonly class MovieSearchResult
{
    /** @param list<MovieSummary> $movies */
    public function __construct(
        public string $query,
        public float $priority,
        public array $movies,
    ) {}
}
