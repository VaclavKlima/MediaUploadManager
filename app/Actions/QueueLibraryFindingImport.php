<?php

namespace App\Actions;

use App\Jobs\ImportLibraryFinding;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class QueueLibraryFindingImport
{
    public function execute(LibraryFinding $finding, User $actor): bool
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException('Only an administrator may import discovered files.');
        }

        $queued = DB::transaction(function () use ($finding): bool {
            $locked = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'import_queued' || $locked->status === 'importing') {
                return false;
            }

            $claimType = $locked->operation_claim['type'] ?? null;

            if ($locked->kind !== 'discovered'
                || $locked->resolved_at !== null
                || ! in_array($locked->status, ['ready', 'failed'], true)
                || $locked->identity_snapshot === null
                || $locked->destination_relative_path === null
                || $claimType === 'delete'
            ) {
                throw new RuntimeException('This finding is not ready to import.');
            }

            $locked->update([
                'status' => 'import_queued',
                'error_detail' => null,
            ]);

            return true;
        }, attempts: 3);

        if ($queued) {
            ImportLibraryFinding::dispatch($finding->id, $actor->id);
        }

        return $queued;
    }
}
