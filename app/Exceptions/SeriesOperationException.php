<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class SeriesOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
