<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;
use Throwable;

class UploadProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $safeDetail,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeDetail, previous: $previous);
    }

    public static function permanent(
        string $errorCode,
        string $safeDetail,
        ?Throwable $previous = null,
    ): self {
        return new self($errorCode, $safeDetail, false, $previous);
    }

    public static function transient(
        string $errorCode,
        string $safeDetail,
        ?Throwable $previous = null,
    ): self {
        return new self($errorCode, $safeDetail, true, $previous);
    }
}
