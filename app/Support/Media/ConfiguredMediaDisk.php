<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;

final readonly class ConfiguredMediaDisk
{
    public function __construct(
        public string $id,
        public string $label,
        public string $root,
        public int $safetyReserveBytes,
        public MediaRootKind $kind = MediaRootKind::Movies,
        public ?SeriesCategory $seriesDefaultCategory = null,
    ) {}
}
