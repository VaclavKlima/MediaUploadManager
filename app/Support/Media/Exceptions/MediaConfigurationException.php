<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

final class MediaConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Media disk configuration is invalid.');
    }
}
