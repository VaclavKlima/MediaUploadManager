<?php

namespace App\Enums;

enum SeriesBatchStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
