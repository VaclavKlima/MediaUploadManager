<?php

namespace App\Support;

use Illuminate\Support\Str;

class OneTimePasswordGenerator
{
    public function generate(): string
    {
        return Str::password(32);
    }
}
