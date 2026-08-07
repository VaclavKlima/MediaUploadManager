<?php

namespace App\Support\Media;

use InvalidArgumentException;

final readonly class CapacityProjection
{
    public int $projectedBytes;

    public function __construct(
        public int $usableBytes,
        public int $activeReservedBytes,
        public int $proposedBytes,
    ) {
        if ($usableBytes < 0 || $activeReservedBytes < 0 || $proposedBytes < 0) {
            throw new InvalidArgumentException('Capacity inputs cannot be negative.');
        }

        $reservedAndProposed = $activeReservedBytes > PHP_INT_MAX - $proposedBytes
            ? PHP_INT_MAX
            : $activeReservedBytes + $proposedBytes;
        $this->projectedBytes = $usableBytes - $reservedAndProposed;
    }

    public function eligible(): bool
    {
        return $this->projectedBytes >= 0;
    }
}
