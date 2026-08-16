<?php

namespace App\Support\Series;

use App\ValueObjects\RelativeMediaPath;
use InvalidArgumentException;

final readonly class CanonicalSeriesPath
{
    public function __construct(
        public string $seriesDirectory,
        public string $seasonDirectory,
        public string $episodeDirectory,
        public string $filename,
        public string $relativePath,
        public string $extension,
    ) {
        if (strlen($seriesDirectory) > 255 || strlen($seasonDirectory) > 255 || strlen($episodeDirectory) > 255 || strlen($filename) > 255) {
            throw new InvalidArgumentException('A canonical Series path segment exceeds the filesystem limit.');
        }

        new RelativeMediaPath($relativePath);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'series_directory' => $this->seriesDirectory,
            'season_directory' => $this->seasonDirectory,
            'episode_directory' => $this->episodeDirectory,
            'filename' => $this->filename,
            'relative_path' => $this->relativePath,
            'extension' => $this->extension,
        ];
    }
}
