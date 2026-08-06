<?php

namespace App\Support\Media\Contracts;

use App\Support\Media\MountPointInspection;

interface MountPointChecker
{
    public function inspect(string $resolvedRoot): MountPointInspection;
}
