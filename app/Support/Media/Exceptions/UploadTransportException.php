<?php

namespace App\Support\Media\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

class UploadTransportException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
