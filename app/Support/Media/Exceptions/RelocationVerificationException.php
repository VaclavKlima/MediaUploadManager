<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

class RelocationVerificationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
