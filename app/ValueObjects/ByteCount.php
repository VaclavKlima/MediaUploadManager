<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class ByteCount
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new InvalidArgumentException('A byte count cannot be negative.');
        }
    }

    public static function from(int|self $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }

    public function remainingAfter(int|self $consumed): self
    {
        return new self(max($this->value - self::from($consumed)->value, 0));
    }
}
