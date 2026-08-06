<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Contracts\MountPointChecker;
use App\Support\Media\Exceptions\DiskInitializationException;
use App\Support\Media\Exceptions\MediaPathException;

final readonly class MediaDiskInitializer
{
    public function __construct(
        private MediaFilesystem $filesystem,
        private MediaPathGuard $pathGuard,
        private MountPointChecker $mountPointChecker,
    ) {}

    public function initialize(ConfiguredMediaDisk $disk, bool $requireMountpoint): bool
    {
        try {
            $resolvedRoot = $this->pathGuard->resolveRoot($disk->root);
        } catch (MediaPathException $exception) {
            throw new DiskInitializationException(
                $exception->reason,
                $exception->reason === 'root_missing'
                    ? 'The configured disk root does not exist.'
                    : 'The configured disk root is unsafe.',
            );
        }

        if ($requireMountpoint) {
            $mountInspection = $this->mountPointChecker->inspect($resolvedRoot);

            if (! $mountInspection->available) {
                throw new DiskInitializationException('mount_info_unavailable', 'Mount information is unavailable.');
            }

            if (! $mountInspection->exactMountPoint) {
                throw new DiskInitializationException('not_exact_mountpoint', 'The disk root is not an exact mount point.');
            }
        }

        try {
            $privateDirectory = $this->pathGuard->resolveChild($disk->root, '.media-upload-manager');
            $incomingDirectory = $this->pathGuard->resolveChild($disk->root, '.media-upload-manager/incoming');
            $markerPath = $this->pathGuard->resolveChild($disk->root, '.media-upload-manager/disk.json');
        } catch (MediaPathException) {
            throw new DiskInitializationException('unsafe_private_path', 'The private disk path is unsafe.');
        }

        $markerExists = $this->filesystem->pathExists($markerPath);

        if ($markerExists) {
            $this->requireMatchingMarker($markerPath, $disk->id);
        }

        $this->ensureDirectory($privateDirectory);
        $this->ensureDirectory($incomingDirectory);

        if ($markerExists) {
            return false;
        }

        if ($this->filesystem->writeFileExclusively($markerPath, DiskMarker::encode($disk->id))) {
            return true;
        }

        if ($this->filesystem->pathExists($markerPath)) {
            $this->requireMatchingMarker($markerPath, $disk->id);

            return false;
        }

        throw new DiskInitializationException('marker_write_failed', 'The disk marker could not be created.');
    }

    private function ensureDirectory(string $path): void
    {
        if ($this->filesystem->pathExists($path)) {
            if (! $this->filesystem->isDirectory($path) || $this->filesystem->isSymbolicLink($path)) {
                throw new DiskInitializationException('private_path_conflict', 'A private disk path conflicts with an existing entry.');
            }

            return;
        }

        if (! $this->filesystem->createDirectory($path) || ! $this->filesystem->isDirectory($path)) {
            throw new DiskInitializationException('directory_create_failed', 'The private disk directory could not be created.');
        }
    }

    private function requireMatchingMarker(string $markerPath, string $diskId): void
    {
        if ($this->filesystem->isSymbolicLink($markerPath) || $this->filesystem->isDirectory($markerPath)) {
            throw new DiskInitializationException('marker_conflict', 'The existing disk marker is malformed or conflicting.');
        }

        $contents = $this->filesystem->readFile($markerPath);
        $marker = $contents === null ? null : DiskMarker::parse($contents);

        if ($marker === null || $marker['disk_id'] !== $diskId) {
            throw new DiskInitializationException('marker_conflict', 'The existing disk marker is malformed or conflicting.');
        }
    }
}
