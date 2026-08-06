<?php

namespace App\Support\Tmdb\Data;

use App\Models\MediaItem;

final readonly class MovieDetails
{
    /**
     * @param  list<array{id: int, name: string}>  $genres
     */
    public function __construct(
        public int $tmdbId,
        public ?string $imdbId,
        public string $title,
        public ?string $originalTitle,
        public ?string $releaseDate,
        public ?int $releaseYear,
        public ?string $overview,
        public ?string $posterPath,
        public ?string $originalLanguage,
        public ?int $runtime,
        public ?string $status,
        public ?string $tagline,
        public ?float $voteAverage,
        public ?int $voteCount,
        public array $genres,
    ) {}

    /**
     * @param  array{tmdb_id: int, imdb_id: string|null, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, original_language: string|null, runtime: int|null, status: string|null, tagline: string|null, vote_average: float|null, vote_count: int|null, genres: list<array{id: int, name: string}>}  $movie
     */
    public static function fromArray(array $movie): self
    {
        return new self(
            tmdbId: $movie['tmdb_id'],
            imdbId: $movie['imdb_id'],
            title: $movie['title'],
            originalTitle: $movie['original_title'],
            releaseDate: $movie['release_date'],
            releaseYear: $movie['release_year'],
            overview: $movie['overview'],
            posterPath: $movie['poster_path'],
            originalLanguage: $movie['original_language'],
            runtime: $movie['runtime'],
            status: $movie['status'],
            tagline: $movie['tagline'],
            voteAverage: $movie['vote_average'],
            voteCount: $movie['vote_count'],
            genres: $movie['genres'],
        );
    }

    public static function fromMediaItem(MediaItem $mediaItem): self
    {
        $snapshot = $mediaItem->metadata_snapshot;

        return new self(
            tmdbId: $mediaItem->tmdb_id,
            imdbId: $mediaItem->imdb_id,
            title: $mediaItem->title,
            originalTitle: $mediaItem->original_title,
            releaseDate: $mediaItem->release_date?->toDateString(),
            releaseYear: $mediaItem->release_year,
            overview: $mediaItem->overview,
            posterPath: $mediaItem->poster_path,
            originalLanguage: $mediaItem->original_language,
            runtime: is_int($snapshot['runtime'] ?? null) ? $snapshot['runtime'] : null,
            status: is_string($snapshot['status'] ?? null) ? $snapshot['status'] : null,
            tagline: is_string($snapshot['tagline'] ?? null) ? $snapshot['tagline'] : null,
            voteAverage: is_float($snapshot['vote_average'] ?? null) || is_int($snapshot['vote_average'] ?? null) ? (float) $snapshot['vote_average'] : null,
            voteCount: is_int($snapshot['vote_count'] ?? null) ? $snapshot['vote_count'] : null,
            genres: self::genresFromSnapshot($snapshot['genres'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->snapshot(),
            'poster_url' => $this->posterPath === null ? null : 'https://image.tmdb.org/t/p/w500'.$this->posterPath,
        ];
    }

    /** @return array{tmdb_id: int, imdb_id: string|null, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, original_language: string|null, runtime: int|null, status: string|null, tagline: string|null, vote_average: float|null, vote_count: int|null, genres: list<array{id: int, name: string}>} */
    public function snapshot(): array
    {
        return [
            'tmdb_id' => $this->tmdbId,
            'imdb_id' => $this->imdbId,
            'title' => $this->title,
            'original_title' => $this->originalTitle,
            'release_date' => $this->releaseDate,
            'release_year' => $this->releaseYear,
            'overview' => $this->overview,
            'poster_path' => $this->posterPath,
            'original_language' => $this->originalLanguage,
            'runtime' => $this->runtime,
            'status' => $this->status,
            'tagline' => $this->tagline,
            'vote_average' => $this->voteAverage,
            'vote_count' => $this->voteCount,
            'genres' => $this->genres,
        ];
    }

    /** @return array{tmdb_id: int, imdb_id: string|null, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, original_language: string|null, metadata_version: int, metadata_snapshot: array<string, mixed>} */
    public function mediaItemSnapshot(): array
    {
        return [
            'tmdb_id' => $this->tmdbId,
            'imdb_id' => $this->imdbId,
            'title' => $this->title,
            'original_title' => $this->originalTitle,
            'release_date' => $this->releaseDate,
            'release_year' => $this->releaseYear,
            'overview' => $this->overview,
            'poster_path' => $this->posterPath,
            'original_language' => $this->originalLanguage,
            'metadata_version' => 1,
            'metadata_snapshot' => $this->snapshot(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function genresFromSnapshot(mixed $genres): array
    {
        if (! is_array($genres)) {
            return [];
        }

        return array_values(array_filter($genres, fn (mixed $genre): bool => is_array($genre)
            && is_int($genre['id'] ?? null)
            && is_string($genre['name'] ?? null)));
    }
}
