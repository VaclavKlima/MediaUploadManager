<?php

namespace App\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RelativeMediaPath
{
    public function __construct(public string $value)
    {
        if ($value === '' || Str::length($value) > 1024) {
            throw new InvalidArgumentException('A relative media path must contain between 1 and 1024 characters.');
        }

        if (Str::startsWith($value, '/') || Str::contains($value, ['\\', "\0"])) {
            throw new InvalidArgumentException('A relative media path cannot be absolute or contain backslashes or NUL bytes.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('A relative media path cannot contain control characters.');
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('A relative media path contains an unsafe segment.');
            }
        }
    }
}
