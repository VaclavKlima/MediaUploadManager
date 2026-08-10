<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

final class HardLinkCreationException extends RuntimeException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct('Hard-link creation was denied by the media filesystem.');
    }

    public static function permissionDenied(): self
    {
        return new self('permission_denied');
    }
}
