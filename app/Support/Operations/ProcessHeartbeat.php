<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Cache;

final class ProcessHeartbeat
{
    public const QUEUE_WORKER_KEY = 'operations:heartbeat:queue-worker';

    public const SCHEDULER_KEY = 'operations:heartbeat:scheduler';

    public static function recordQueueWorker(): void
    {
        Cache::put(self::QUEUE_WORKER_KEY, now()->getTimestamp(), now()->addMinutes(5));
    }

    public static function recordScheduler(): void
    {
        Cache::put(self::SCHEDULER_KEY, now()->getTimestamp(), now()->addMinutes(5));
    }
}
