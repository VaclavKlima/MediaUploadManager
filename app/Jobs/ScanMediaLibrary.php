<?php

namespace App\Jobs;

use App\Models\LibraryScan;
use App\Support\Media\MediaLibraryScanProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScanMediaLibrary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [5, 30, 90];

    public function __construct(public readonly int $scanId) {}

    public function handle(MediaLibraryScanProcessor $processor): void
    {
        $processor->process($this->scanId);
    }

    public function failed(?Throwable $exception): void
    {
        LibraryScan::query()->whereKey($this->scanId)->update([
            'status' => 'failed',
            'error_detail' => $exception?->getMessage() ?? 'The library scan failed.',
            'completed_at' => now(),
        ]);
    }
}
