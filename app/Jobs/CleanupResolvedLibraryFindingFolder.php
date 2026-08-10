<?php

namespace App\Jobs;

use App\Actions\CleanupResolvedLibraryFindingFolder as CleanupResolvedFolder;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupResolvedLibraryFindingFolder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $findingId,
        public readonly int $actorId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CleanupResolvedFolder $cleanup): void
    {
        $cleanup->execute(
            LibraryFinding::query()->findOrFail($this->findingId),
            User::query()->findOrFail($this->actorId),
        );
    }

    public function uniqueId(): string
    {
        return (string) $this->findingId;
    }
}
