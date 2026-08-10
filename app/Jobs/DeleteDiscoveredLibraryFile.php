<?php

namespace App\Jobs;

use App\Actions\DeleteLibraryFinding;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeleteDiscoveredLibraryFile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $findingId, public readonly int $actorId) {}

    /**
     * Execute the job.
     */
    public function handle(DeleteLibraryFinding $deletion): void
    {
        $deletion->process(
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
        LibraryFinding::query()->whereKey($this->findingId)->whereNull('resolved_at')->update([
            'status' => 'failed',
            'error_detail' => $exception?->getMessage() ?? 'The file deletion failed.',
        ]);
    }
}
