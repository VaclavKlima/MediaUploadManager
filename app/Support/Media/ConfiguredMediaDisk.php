<?php

namespace App\Support\Media;

final readonly class ConfiguredMediaDisk
{
    public function __construct(
        public string $id,
        public string $label,
        public string $root,
        public int $safetyReserveBytes,
    ) {}
}
