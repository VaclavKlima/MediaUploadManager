<?php

namespace App\Support\Media;

final readonly class MountPointInspection
{
    public function __construct(
        public bool $available,
        public bool $exactMountPoint,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, false);
    }

    public static function detected(bool $exactMountPoint): self
    {
        return new self(true, $exactMountPoint);
    }
}
