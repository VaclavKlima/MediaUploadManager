<?php

namespace App\Actions;

use App\Jobs\ImportLibraryFinding;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QueueLibraryFindingRestore
{
    public function execute(LibraryFinding $finding, User $actor): bool
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException('Only an administrator may restore moved files.');
        }

        $queued = DB::transaction(function () use ($finding): bool {
            $locked = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['restore_queued', 'restoring'], true)) {
                return false;
            }

            if ($locked->kind !== 'discovered'
                || $locked->resolved_at !== null
                || $locked->paired_missing_finding_id === null
                || ! in_array($locked->status, ['restore_ready', 'failed'], true)
                || (($locked->operation_claim['type'] ?? 'restore') !== 'restore')
            ) {
                throw new RuntimeException('This finding is not ready to restore.');
            }

            $locked->update(['status' => 'restore_queued', 'error_detail' => null]);

            return true;
        }, attempts: 3);

        if ($queued) {
            ImportLibraryFinding::dispatch($finding->id, $actor->id);
        }

        return $queued;
    }
}
