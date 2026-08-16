<?php

namespace App\Support\Tmdb;

use App\Support\Tmdb\Data\ParsedFilename;
use Illuminate\Support\Str;
use Normalizer;

class SeriesSourceParser
{
    public function __construct(private readonly FilenameParser $filenameParser) {}

    public function parse(string $sourceName): ParsedFilename
    {
        $normalized = Normalizer::normalize($sourceName, Normalizer::FORM_C);
        $sourceName = is_string($normalized) ? $normalized : $sourceName;
        $identityOffset = $this->episodeIdentityOffset($sourceName);

        if ($identityOffset !== null) {
            $showName = Str::of(substr($sourceName, 0, $identityOffset))
                ->trim(" \t\n\r\0\x0B._-–—()[]{}")
                ->toString();

            if ($showName !== '') {
                return $this->filenameParser->parse($showName);
            }
        }

        return $this->filenameParser->parse($sourceName);
    }

    private function episodeIdentityOffset(string $sourceName): ?int
    {
        $matched = preg_match(
            '/(?<![\pL\pN])S\d{1,4}[._\-\s]*E\d{1,4}(?!\d)/iu',
            $sourceName,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        return $matched === 1 ? $matches[0][1] : null;
    }
}
