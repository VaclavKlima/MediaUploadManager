<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

final class MediaPathException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The media path is unavailable or unsafe.');
    }
}
