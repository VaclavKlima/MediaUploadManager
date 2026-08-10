<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Jobs\DeleteDiscoveredLibraryFile;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\SecurityAudit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class DeleteLibraryFinding
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private CleanupResolvedLibraryFindingFolder $cleanupResolvedFolder,
    ) {}

    public function confirm(LibraryFinding $finding, User $actor, bool $confirmed): void
    {
        if (! $actor->isAdministrator() || ! $confirmed) {
            throw new RuntimeException('Confirm permanent deletion as an administrator.');
        }

        $claim = DB::transaction(function () use ($finding, $actor): array {
            $finding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($finding->resolution === 'deleted') {
                return $finding->operation_claim ?? [];
            }

            if ($finding->kind !== 'discovered'
                || $finding->resolved_at !== null
                || ($finding->operation_claim !== null && ($finding->operation_claim['type'] ?? null) !== 'delete')
            ) {
                throw new RuntimeException('This finding can no longer be deleted.');
            }

            if ($finding->operation_claim !== null) {
                $finding->update([
                    'status' => 'deleting',
                    'error_detail' => null,
                ]);

                return $finding->operation_claim;
            }

            $disk = $this->healthyDisk($finding->disk_id);
            $path = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);
            $this->assertSnapshot($finding, $path);
            $this->assertUnclaimed($finding);
            $claim = [
                'version' => 1,
                'type' => 'delete',
                'actor_id' => $actor->id,
                'disk_id' => $finding->disk_id,
                'relative_path' => $finding->relative_path,
                'size_bytes' => $finding->size_bytes,
                'device_id' => $finding->device_id,
                'inode_id' => $finding->inode_id,
            ];
            $finding->update([
                'operation_claim' => $claim,
                'status' => 'deleting',
                'error_detail' => null,
            ]);

            SecurityAudit::libraryFileDeletionConfirmed($finding, $actor);

            return $claim;
        }, attempts: 3);

        if ($claim !== []) {
            DeleteDiscoveredLibraryFile::dispatch($finding->id, $actor->id);
        }
    }

    public function process(LibraryFinding $finding, User $actor): void
    {
        $finding = $finding->refresh();

        if ($finding->resolution === 'deleted') {
            $this->cleanupResolvedFolder->execute($finding, $actor);

            return;
        }

        $claim = $finding->operation_claim;

        if ($claim === null
            || ($claim['type'] ?? null) !== 'delete'
            || ($claim['actor_id'] ?? null) !== $actor->id
            || ($claim['disk_id'] ?? null) !== $finding->disk_id
            || ($claim['relative_path'] ?? null) !== $finding->relative_path
        ) {
            throw new RuntimeException('The persisted deletion claim is invalid.');
        }

        $disk = $this->healthyDisk($finding->disk_id);
        $path = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);

        if ($this->filesystem->pathExists($path)) {
            $this->assertSnapshot($finding, $path);
            $this->assertUnclaimed($finding);

            if (! $this->filesystem->deleteFile($path)) {
                throw new RuntimeException('The exact discovered file could not be deleted.');
            }
        }

        $finding->update([
            'status' => 'resolved',
            'resolution' => 'deleted',
            'resolved_at' => now(),
            'error_detail' => null,
        ]);
        SecurityAudit::libraryFileDeletionCompleted($finding, $actor);
        $this->cleanupResolvedFolder->execute($finding->refresh(), $actor);
    }

    private function assertUnclaimed(LibraryFinding $finding): void
    {
        if (MediaFile::query()
            ->where('disk_id', $finding->disk_id)
            ->where('relative_path', $finding->relative_path)
            ->whereNotNull('active_path_key')
            ->exists()
            || Upload::query()
                ->where('disk_id', $finding->disk_id)
                ->where(function ($query) use ($finding): void {
                    $query->where('target_relative_path', $finding->relative_path)
                        ->orWhere('staging_relative_path', $finding->relative_path);
                })
                ->whereNotIn('status', [UploadStatus::Cancelled, UploadStatus::Expired])
                ->exists()
        ) {
            throw new RuntimeException('The file is now tracked or claimed by an upload.');
        }
    }

    private function assertSnapshot(LibraryFinding $finding, string $path): void
    {
        if (! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $finding->size_bytes
            || $this->filesystem->deviceId($path) !== $finding->device_id
            || $this->filesystem->inodeId($path) !== $finding->inode_id
        ) {
            throw new RuntimeException('The file no longer matches its scan snapshot.');
        }
    }

    private function healthyDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->find($diskId);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw new RuntimeException('The source disk is unavailable or its marker identity changed.');
        }

        return $disk;
    }
}
