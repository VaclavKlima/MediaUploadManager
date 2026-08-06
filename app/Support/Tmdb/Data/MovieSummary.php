<?php

namespace App\Support\Tmdb\Data;

final readonly class MovieSummary
{
    public function __construct(
        public int $tmdbId,
        public string $title,
        public ?string $originalTitle,
        public ?string $releaseDate,
        public ?int $releaseYear,
        public ?string $overview,
        public ?string $posterPath,
        public ?string $originalLanguage,
    ) {}

    /**
     * @param  array{tmdb_id: int, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, original_language: string|null}  $movie
     */
    public static function fromArray(array $movie): self
    {
        return new self(
            tmdbId: $movie['tmdb_id'],
            title: $movie['title'],
            originalTitle: $movie['original_title'],
            releaseDate: $movie['release_date'],
            releaseYear: $movie['release_year'],
            overview: $movie['overview'],
            posterPath: $movie['poster_path'],
            originalLanguage: $movie['original_language'],
        );
    }

    /** @return array{tmdb_id: int, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, poster_url: string|null, original_language: string|null} */
    public function toArray(): array
    {
        return [
            'tmdb_id' => $this->tmdbId,
            'title' => $this->title,
            'original_title' => $this->originalTitle,
            'release_date' => $this->releaseDate,
            'release_year' => $this->releaseYear,
            'overview' => $this->overview,
            'poster_path' => $this->posterPath,
            'poster_url' => $this->posterPath === null ? null : 'https://image.tmdb.org/t/p/w342'.$this->posterPath,
            'original_language' => $this->originalLanguage,
        ];
    }
}
