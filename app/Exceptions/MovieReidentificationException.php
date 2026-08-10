<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MovieReidentificationException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
