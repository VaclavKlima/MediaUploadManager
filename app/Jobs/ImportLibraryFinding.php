<?php

namespace App\Jobs;

use App\Models\LibraryFinding;
use App\Models\User;
use App\Support\Media\LibraryImportProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportLibraryFinding implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [15, 60, 120];

    public function __construct(
        public readonly int $findingId,
        public readonly int $actorId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LibraryImportProcessor $processor): void
    {
        $processor->process(
            LibraryFinding::query()->findOrFail($this->findingId),
            User::query()->findOrFail($this->actorId),
        );
    }

    public function uniqueId(): string
    {
        return (string) $this->findingId;
    }

    public function failed(?Throwable $exception): void
    {
        LibraryFinding::query()
            ->whereKey($this->findingId)
            ->whereNull('resolved_at')
            ->whereIn('status', ['import_queued', 'importing', 'restore_queued', 'restoring'])
            ->update([
                'status' => 'failed',
                'error_detail' => $exception?->getMessage() ?? 'The file operation failed.',
            ]);
    }
}
