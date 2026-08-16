<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Models\FolderCleanup;
use App\Models\User;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\FolderCleanupNotRequired;
use App\Support\SecurityAudit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final readonly class FolderCleanupProcessor
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
    ) {}

    /**
     * @return array{relative_folder: string, entries: list<array<string, bool|int|string>>, file_count: int, total_size_bytes: int}
     */
    public function preview(
        string $diskId,
        string $relativeFolder,
        MediaRootKind $rootKind = MediaRootKind::Movies,
    ): array {
        if ($relativeFolder === '') {
            throw new FolderCleanupNotRequired('The configured disk root can never be cleaned up.');
        }

        $disk = $this->healthyDisk($diskId, $rootKind);
        [$relativeFolder, $rootPath] = $this->resolveCleanupFolder($disk, $relativeFolder);
        [$relativeFolder, $rootPath] = $this->highestCleanupFolder($disk, $relativeFolder, $rootPath);

        $entries = [$this->directoryEntry($relativeFolder, $rootPath, true)];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        $fileCount = 0;
        $totalSize = 0;

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            $relativePath = substr($entry->getPathname(), strlen(rtrim($disk->root, '/')) + 1);

            if ($relativePath === '') {
                throw new RuntimeException('A cleanup residue path could not be guarded.');
            }

            $path = $this->pathGuard->resolveChild($disk->root, $relativePath);

            if ($entry->isLink()) {
                throw new RuntimeException('Cleanup is blocked because the residue contains a symbolic link.');
            }

            if ($entry->isDir()) {
                $entries[] = $this->directoryEntry($relativePath, $path, false);

                continue;
            }

            if (! $entry->isFile() || ! $this->filesystem->isRegularFile($path)) {
                throw new RuntimeException('Cleanup is blocked because the residue contains a special file.');
            }

            if (in_array(strtolower($entry->getExtension()), JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)) {
                throw new FolderCleanupNotRequired('Cleanup is blocked while a supported video remains beneath the folder.');
            }

            $size = $this->filesystem->fileSize($path);
            $device = $this->filesystem->deviceId($path);
            $inode = $this->filesystem->inodeId($path);

            if ($size === null || $device === null || $inode === null) {
                throw new RuntimeException('A cleanup residue file could not be identified safely.');
            }

            $entries[] = [
                'type' => 'file',
                'relative_path' => $relativePath,
                'size_bytes' => $size,
                'device_id' => $device,
                'inode_id' => $inode,
                'root' => false,
            ];
            $fileCount++;
            $totalSize += $size;
        }

        usort($entries, fn (array $left, array $right): int => (string) $left['relative_path'] <=> (string) $right['relative_path']);

        return [
            'relative_folder' => $relativeFolder,
            'entries' => $entries,
            'file_count' => $fileCount,
            'total_size_bytes' => $totalSize,
        ];
    }

    public function process(FolderCleanup $cleanup, User $actor): void
    {
        $cleanup = $cleanup->refresh();

        if ($cleanup->status === 'completed') {
            return;
        }

        if ($cleanup->confirmed_at === null || $cleanup->status !== 'deleting') {
            throw new RuntimeException('The cleanup manifest has not been confirmed.');
        }

        $claimedEntries = collect($cleanup->manifest)->keyBy('relative_path');
        $rootKind = $cleanup->libraryFinding->root_kind ?? MediaRootKind::Movies;
        $disk = $this->healthyDisk($cleanup->disk_id, $rootKind);

        if ($this->claimedEntriesAreAbsent($disk, $claimedEntries->all())) {
            $this->complete($cleanup, $actor, true);

            return;
        }

        $rootPath = $this->pathGuard->resolveChild($disk->root, $cleanup->relative_folder);
        $currentEntries = $this->filesystem->pathExists($rootPath)
            ? collect($this->preview($cleanup->disk_id, $cleanup->relative_folder, $rootKind)['entries'])->keyBy('relative_path')
            : collect($this->existingClaimedEntries($disk, $claimedEntries->all()))->keyBy('relative_path');

        foreach ($claimedEntries as $claimed) {
            $relativePath = $this->entryRelativePath($claimed);
            $currentEntry = $currentEntries->get($relativePath);

            if ($currentEntry === null) {
                continue;
            }

            if (($currentEntry['type'] ?? null) !== ($claimed['type'] ?? null)
                || ($currentEntry['device_id'] ?? null) !== ($claimed['device_id'] ?? null)
                || ($currentEntry['inode_id'] ?? null) !== ($claimed['inode_id'] ?? null)
                || (($claimed['type'] ?? null) === 'file'
                    && ($currentEntry['size_bytes'] ?? null) !== ($claimed['size_bytes'] ?? null))
            ) {
                throw new RuntimeException('Cleanup stopped because a confirmed residue entry changed.');
            }
        }

        $files = $claimedEntries->where('type', 'file')->sortByDesc(
            fn (array $entry): int => substr_count($this->entryRelativePath($entry), '/'),
        );

        foreach ($files as $entry) {
            $path = $this->pathGuard->resolveChild($disk->root, $this->entryRelativePath($entry));

            if ($this->filesystem->pathExists($path) && ! $this->filesystem->deleteFile($path)) {
                throw new RuntimeException('A confirmed residue file could not be deleted.');
            }
        }

        $directories = $claimedEntries->where('type', 'directory')->sortByDesc(
            fn (array $entry): int => substr_count($this->entryRelativePath($entry), '/'),
        );

        foreach ($directories as $entry) {
            $path = $this->pathGuard->resolveChild($disk->root, $this->entryRelativePath($entry));

            if ($this->filesystem->pathExists($path)) {
                $this->filesystem->removeDirectoryIfEmpty($path);
            }
        }

        $completed = $this->claimedEntriesAreAbsent($disk, $claimedEntries->all());
        $this->complete($cleanup, $actor, $completed);
    }

    /** @param array<array<string, mixed>> $claimedEntries */
    private function claimedEntriesAreAbsent(ConfiguredMediaDisk $disk, array $claimedEntries): bool
    {
        foreach ($claimedEntries as $entry) {
            $path = $this->pathGuard->resolveChild($disk->root, $this->entryRelativePath($entry));

            if ($this->filesystem->pathExists($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array<string, mixed>>  $claimedEntries
     * @return list<array<string, mixed>>
     */
    private function existingClaimedEntries(ConfiguredMediaDisk $disk, array $claimedEntries): array
    {
        $entries = [];

        foreach ($claimedEntries as $claimed) {
            $relativePath = $this->entryRelativePath($claimed);
            $path = $this->pathGuard->resolveChild($disk->root, $relativePath);

            if (! $this->filesystem->pathExists($path)) {
                continue;
            }

            if (($claimed['type'] ?? null) === 'directory'
                && $this->filesystem->isDirectory($path)
                && ! $this->filesystem->isSymbolicLink($path)
            ) {
                $entries[] = $this->directoryEntry($relativePath, $path, (bool) ($claimed['root'] ?? false));

                continue;
            }

            if (($claimed['type'] ?? null) === 'file' && $this->filesystem->isRegularFile($path)) {
                $entries[] = [
                    'type' => 'file',
                    'relative_path' => $relativePath,
                    'size_bytes' => $this->filesystem->fileSize($path),
                    'device_id' => $this->filesystem->deviceId($path),
                    'inode_id' => $this->filesystem->inodeId($path),
                    'root' => false,
                ];

                continue;
            }

            throw new RuntimeException('Cleanup stopped because a confirmed residue entry became unsafe.');
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private function entryRelativePath(array $entry): string
    {
        $relativePath = $entry['relative_path'] ?? null;

        if (! is_string($relativePath) || $relativePath === '') {
            throw new RuntimeException('The cleanup manifest contains an invalid relative path.');
        }

        return $relativePath;
    }

    private function complete(FolderCleanup $cleanup, User $actor, bool $completed): void
    {
        $cleanup->update([
            'status' => $completed ? 'completed' : 'partial',
            'error_detail' => $completed ? null : 'New residue appeared after confirmation and was retained.',
            'completed_at' => now(),
        ]);
        SecurityAudit::folderCleanupCompleted($cleanup, $actor);
    }

    /** @return array{string, string} */
    private function resolveCleanupFolder(ConfiguredMediaDisk $disk, string $relativeFolder): array
    {
        $candidate = $relativeFolder;

        while (true) {
            $path = $this->pathGuard->resolveChild($disk->root, $candidate);

            if ($this->filesystem->pathExists($path)) {
                if (! $this->filesystem->isDirectory($path) || $this->filesystem->isSymbolicLink($path)) {
                    throw new RuntimeException('The cleanup folder is unsafe.');
                }

                return [$candidate, $path];
            }

            $parent = dirname($candidate);

            if ($parent === '.' || $parent === '') {
                throw new FolderCleanupNotRequired('The old folder and its empty parents have already been cleaned up.');
            }

            $candidate = $parent;
        }
    }

    /** @return array{string, string} */
    private function highestCleanupFolder(ConfiguredMediaDisk $disk, string $relativeFolder, string $rootPath): array
    {
        $candidate = $relativeFolder;
        $candidatePath = $rootPath;

        while (true) {
            $parent = dirname($candidate);

            if ($parent === '.' || $parent === '') {
                break;
            }

            $parentPath = $this->pathGuard->resolveChild($disk->root, $parent);

            if (! $this->containsOnlyCleanupResidue($parentPath)) {
                break;
            }

            $candidate = $parent;
            $candidatePath = $parentPath;
        }

        return [$candidate, $candidatePath];
    }

    private function containsOnlyCleanupResidue(string $path): bool
    {
        if (! $this->filesystem->isDirectory($path) || $this->filesystem->isSymbolicLink($path)) {
            return false;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo || $entry->isLink()) {
                    return false;
                }

                if ($entry->isDir()) {
                    continue;
                }

                if (! $entry->isFile() || ! $this->filesystem->isRegularFile($entry->getPathname())) {
                    return false;
                }

                if (in_array(strtolower($entry->getExtension()), JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return array{type: string, relative_path: string, size_bytes: int, device_id: int, inode_id: int, root: bool} */
    private function directoryEntry(string $relativePath, string $path, bool $root): array
    {
        $device = $this->filesystem->deviceId($path);
        $inode = $this->filesystem->inodeId($path);

        if ($device === null || $inode === null) {
            throw new RuntimeException('A cleanup residue directory could not be identified safely.');
        }

        return [
            'type' => 'directory',
            'relative_path' => $relativePath,
            'size_bytes' => 0,
            'device_id' => $device,
            'inode_id' => $inode,
            'root' => $root,
        ];
    }

    private function healthyDisk(string $diskId, MediaRootKind $rootKind): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->findRoot($diskId, $rootKind);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw new RuntimeException('The cleanup disk is unavailable or its marker identity changed.');
        }

        return $disk;
    }
}
