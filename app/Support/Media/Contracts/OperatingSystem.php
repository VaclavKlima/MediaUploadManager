<?php

namespace App\Support\Media\Contracts;

interface OperatingSystem
{
    public function isLinux(): bool;
}
