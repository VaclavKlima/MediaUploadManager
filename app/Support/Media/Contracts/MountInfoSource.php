<?php

namespace App\Support\Media\Contracts;

interface MountInfoSource
{
    public function read(): ?string;
}
