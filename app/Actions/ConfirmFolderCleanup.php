<?php

namespace App\Actions;

use App\Jobs\ProcessFolderCleanup;
use App\Models\FolderCleanup;
use App\Models\User;
use App\Support\SecurityAudit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ConfirmFolderCleanup
{
    public function execute(FolderCleanup $cleanup, User $actor, string $manifestHash, bool $confirmed): void
    {
        if (! $actor->isAdministrator() || ! $confirmed) {
            throw new RuntimeException('Confirm irreversible folder cleanup as an administrator.');
        }

        DB::transaction(function () use ($cleanup, $actor, $manifestHash): void {
            $cleanup = FolderCleanup::query()->whereKey($cleanup)->lockForUpdate()->firstOrFail();

            if ($cleanup->status === 'completed') {
                return;
            }

            if ($cleanup->manifest_hash !== $manifestHash
                || ! in_array($cleanup->status, ['previewed', 'deleting', 'partial', 'failed'], true)
            ) {
                throw new RuntimeException('The cleanup manifest confirmation is stale or invalid.');
            }

            if ($cleanup->confirmed_at === null) {
                $cleanup->update(['status' => 'deleting', 'confirmed_at' => now(), 'error_detail' => null]);
                SecurityAudit::folderCleanupConfirmed($cleanup, $actor);
            } else {
                $cleanup->update(['status' => 'deleting', 'error_detail' => null]);
            }
        }, attempts: 3);

        ProcessFolderCleanup::dispatch($cleanup->id, $actor->id);
    }
}
