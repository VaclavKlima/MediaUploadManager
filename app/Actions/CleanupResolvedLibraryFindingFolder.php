<?php

namespace App\Actions;

use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\User;
use App\Support\Media\Exceptions\FolderCleanupNotRequired;
use App\Support\Media\FolderCleanupProcessor;
use App\Support\SecurityAudit;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class CleanupResolvedLibraryFindingFolder
{
    public function __construct(
        private PreviewFolderCleanup $previewFolderCleanup,
        private FolderCleanupProcessor $processor,
    ) {}

    public function execute(LibraryFinding $finding, User $actor): ?FolderCleanup
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException('Only an administrator may clean resolved library folders.');
        }

        $finding = $finding->refresh();

        if ($finding->resolved_at === null || ! in_array($finding->resolution, ['imported', 'deleted', 'relocated'], true)) {
            throw new RuntimeException('Only an imported, relocated, or deleted finding may clean its source folder.');
        }

        try {
            $cleanup = $this->previewFolderCleanup->execute($finding, $actor);
        } catch (FolderCleanupNotRequired) {
            return null;
        } catch (RuntimeException $exception) {
            $cleanup = FolderCleanup::query()->create([
                'user_id' => $actor->id,
                'library_finding_id' => $finding->id,
                'disk_id' => $finding->disk_id,
                'relative_folder' => $finding->source_folder,
                'status' => 'failed',
                'manifest' => [],
                'manifest_hash' => hash('sha256', '[]'),
                'file_count' => 0,
                'total_size_bytes' => 0,
                'error_detail' => $exception->getMessage(),
            ]);
            Log::warning('Automatic resolved library folder cleanup was skipped.', [
                'library_finding_id' => $finding->id,
                'disk_id' => $finding->disk_id,
                'reason' => $exception->getMessage(),
            ]);

            return $cleanup;
        }

        $cleanup->update([
            'status' => 'deleting',
            'confirmed_at' => now(),
            'error_detail' => null,
        ]);
        SecurityAudit::folderCleanupConfirmed($cleanup, $actor);

        try {
            $this->processor->process($cleanup, $actor);
        } catch (Throwable $exception) {
            $cleanup->update([
                'status' => 'failed',
                'error_detail' => $exception->getMessage(),
            ]);
            Log::warning('Automatic resolved library folder cleanup failed.', [
                'library_finding_id' => $finding->id,
                'folder_cleanup_id' => $cleanup->id,
                'disk_id' => $finding->disk_id,
                'reason' => $exception->getMessage(),
            ]);
        }

        return $cleanup->refresh();
    }
}
