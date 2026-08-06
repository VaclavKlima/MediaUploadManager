<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

final class DiskInitializationException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
