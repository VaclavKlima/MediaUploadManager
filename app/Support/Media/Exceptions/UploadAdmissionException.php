<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;
use Throwable;

class UploadAdmissionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
