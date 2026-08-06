<?php

namespace App\Support\Tmdb\Data;

final readonly class ParsedFilename
{
    /**
     * @param  list<string>  $searchVariants
     */
    public function __construct(
        public string $filename,
        public string $title,
        public ?int $year,
        public array $searchVariants,
    ) {}

    /** @return array{filename: string, title: string, year: int|null} */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'title' => $this->title,
            'year' => $this->year,
        ];
    }
}
