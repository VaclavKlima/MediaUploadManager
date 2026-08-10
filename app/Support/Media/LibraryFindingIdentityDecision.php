<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class LibraryFindingIdentityDecision implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<int>  $duplicateFindingIds
     * @param  array{finding_id: int, media_file_id: int, disk_id: string, relative_path: string, size_bytes: int|null}|null  $relocation
     */
    public function __construct(
        public int $tmdbId,
        public ?string $imdbId,
        public array $snapshot,
        public string $destinationRelativePath,
        public ?int $existingMediaItemId,
        public array $duplicateFindingIds,
        public ?string $blockerCode,
        public ?string $blockerMessage,
        public string $operation = 'import',
        public ?array $relocation = null,
    ) {}

    public function canImport(): bool
    {
        return $this->blockerCode === null;
    }

    /** @return array<string, mixed> */
    public function toArray(string $diskId, string $sourcePath, string $sourceFilename, ?int $sizeBytes): array
    {
        $posterPath = $this->snapshot['poster_path'] ?? null;

        return [
            'source' => [
                'disk_id' => $diskId,
                'relative_path' => $sourcePath,
                'filename' => $sourceFilename,
                'size_bytes' => $sizeBytes,
            ],
            'destination' => [
                'disk_id' => $diskId,
                'relative_path' => $this->destinationRelativePath,
            ],
            'movie' => [
                'tmdb_id' => $this->tmdbId,
                'imdb_id' => $this->imdbId,
                'title' => $this->snapshot['title'],
                'release_year' => $this->snapshot['release_year'] ?? null,
                'poster_url' => is_string($posterPath)
                    ? 'https://image.tmdb.org/t/p/w342'.$posterPath
                    : null,
            ],
            'can_import' => $this->canImport(),
            'operation' => $this->operation,
            'relocation' => $this->relocation,
            'blocker' => $this->blockerCode === null ? null : [
                'code' => $this->blockerCode,
                'message' => $this->blockerMessage,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'tmdb_id' => $this->tmdbId,
            'imdb_id' => $this->imdbId,
            'destination_relative_path' => $this->destinationRelativePath,
            'can_import' => $this->canImport(),
            'operation' => $this->operation,
            'relocation' => $this->relocation,
            'blocker_code' => $this->blockerCode,
            'blocker_message' => $this->blockerMessage,
        ];
    }
}
