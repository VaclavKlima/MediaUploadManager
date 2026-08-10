<?php

namespace App\Jobs;

use App\Models\FolderCleanup;
use App\Models\User;
use App\Support\Media\FolderCleanupProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessFolderCleanup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $cleanupId, public readonly int $actorId) {}

    /**
     * Execute the job.
     */
    public function handle(FolderCleanupProcessor $processor): void
    {
        $processor->process(
            FolderCleanup::query()->findOrFail($this->cleanupId),
            User::query()->findOrFail($this->actorId),
        );
    }

    public function uniqueId(): string
    {
        return (string) $this->cleanupId;
    }

    public function failed(?Throwable $exception): void
    {
        FolderCleanup::query()->whereKey($this->cleanupId)->whereNotIn('status', ['completed', 'partial'])->update([
            'status' => 'failed',
            'error_detail' => $exception?->getMessage() ?? 'Folder cleanup failed.',
        ]);
    }
}
