<?php

namespace App\Enums;

enum DiskInitializationResult: string
{
    case Created = 'created';
    case Upgraded = 'upgraded';
    case AlreadyInitialized = 'already_initialized';
}
