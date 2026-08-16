<?php

namespace App\Support\Series;

final readonly class ParsedEpisodeFilename
{
    public function __construct(
        public string $filename,
        public ?int $seasonNumber,
        public ?int $episodeNumber,
        public ?string $excludedReason = null,
    ) {}

    public function accepted(): bool
    {
        return $this->excludedReason === null && $this->seasonNumber !== null && $this->episodeNumber !== null;
    }

    public function identity(): ?string
    {
        return $this->accepted() ? sprintf('S%02dE%02d', $this->seasonNumber, $this->episodeNumber) : null;
    }
}
