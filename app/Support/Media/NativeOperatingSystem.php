<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\OperatingSystem;

final class NativeOperatingSystem implements OperatingSystem
{
    public function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}
