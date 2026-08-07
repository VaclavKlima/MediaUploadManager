<?php

namespace App\Support\Media;

use InvalidArgumentException;

final readonly class FileFingerprintRanges
{
    public int $firstOffset;

    public int $firstLength;

    public int $lastOffset;

    public int $lastLength;

    public function __construct(public int $fileSize, public int $windowBytes)
    {
        if ($fileSize < 0 || $windowBytes <= 0) {
            throw new InvalidArgumentException('Fingerprint range inputs are invalid.');
        }

        $this->firstOffset = 0;
        $this->firstLength = min($windowBytes, $fileSize);
        $this->lastOffset = max(0, $fileSize - $windowBytes);
        $this->lastLength = $fileSize - $this->lastOffset;
    }
}
