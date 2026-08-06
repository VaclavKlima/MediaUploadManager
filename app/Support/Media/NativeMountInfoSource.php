<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MountInfoSource;

final readonly class NativeMountInfoSource implements MountInfoSource
{
    public function __construct(private string $path = '/proc/self/mountinfo') {}

    public function read(): ?string
    {
        $contents = @file_get_contents($this->path);

        return $contents === false ? null : $contents;
    }
}
