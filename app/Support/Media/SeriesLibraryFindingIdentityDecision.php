<?php

namespace App\Support\Media;

use App\Enums\SeriesCategory;

final readonly class SeriesLibraryFindingIdentityDecision
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<int>  $duplicateFindingIds
     * @param  array{finding_id:int,media_file_id:int,disk_id:string,relative_path:string,size_bytes:int|null}|null  $relocation
     */
    public function __construct(
        public int $tmdbId,
        public SeriesCategory $category,
        public int $seasonNumber,
        public int $episodeNumber,
        public array $snapshot,
        public string $destinationRelativePath,
        public ?int $existingSeriesId,
        public ?int $existingEpisodeId,
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
        $series = $this->snapshot['series'];
        $episode = $this->snapshot['episode'];
        $posterPath = is_array($series) ? ($series['poster_path'] ?? null) : null;

        return [
            'media_type' => 'show',
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
            'show' => [
                'tmdb_id' => $this->tmdbId,
                'name' => is_array($series) ? $series['name'] : null,
                'first_air_year' => is_array($series) ? ($series['first_air_year'] ?? null) : null,
                'poster_url' => is_string($posterPath) ? 'https://image.tmdb.org/t/p/w342'.$posterPath : null,
                'category' => $this->category->value,
                'season_number' => $this->seasonNumber,
                'episode_number' => $this->episodeNumber,
                'episode_name' => is_array($episode) ? ($episode['name'] ?? null) : null,
                'existing_series_id' => $this->existingSeriesId,
                'existing_episode_id' => $this->existingEpisodeId,
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
}
