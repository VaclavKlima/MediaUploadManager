<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Contracts\MountPointChecker;
use App\Support\Media\Exceptions\MediaPathException;
use Throwable;

final readonly class MediaDiskHealthChecker
{
    public function __construct(
        private MediaFilesystem $filesystem,
        private MediaPathGuard $pathGuard,
        private MountPointChecker $mountPointChecker,
    ) {}

    public function check(ConfiguredMediaDisk $disk, bool $requireMountpoint): DiskHealthStatus
    {
        try {
            return $this->performCheck($disk, $requireMountpoint);
        } catch (Throwable) {
            return $this->unhealthy($disk, DiskHealthReason::CheckFailed);
        }
    }

    private function performCheck(ConfiguredMediaDisk $disk, bool $requireMountpoint): DiskHealthStatus
    {
        try {
            $resolvedRoot = $this->pathGuard->resolveRoot($disk->root);
        } catch (MediaPathException $exception) {
            return $this->unhealthy(
                $disk,
                $exception->reason === 'root_missing'
                    ? DiskHealthReason::RootMissing
                    : DiskHealthReason::UnsafeRoot,
            );
        }

        $deviceId = $this->filesystem->deviceId($resolvedRoot);

        if ($requireMountpoint) {
            $mountInspection = $this->mountPointChecker->inspect($resolvedRoot);

            if (! $mountInspection->available) {
                return $this->unhealthy($disk, DiskHealthReason::MountInfoUnavailable, $deviceId);
            }

            if (! $mountInspection->exactMountPoint) {
                return $this->unhealthy($disk, DiskHealthReason::NotExactMountPoint, $deviceId);
            }
        }

        try {
            $markerPath = $this->pathGuard->resolveChild($disk->root, '.media-upload-manager/disk.json');
            $incomingDirectory = $this->pathGuard->resolveChild($disk->root, '.media-upload-manager/incoming');
        } catch (MediaPathException) {
            return $this->unhealthy($disk, DiskHealthReason::UnsafeRoot, $deviceId);
        }

        if (! $this->filesystem->pathExists($markerPath)) {
            return $this->unhealthy($disk, DiskHealthReason::MarkerMissing, $deviceId);
        }

        $markerContents = $this->filesystem->readFile($markerPath);
        $marker = $markerContents === null ? null : DiskMarker::parse($markerContents);

        if ($marker === null) {
            return $this->unhealthy($disk, DiskHealthReason::MarkerInvalid, $deviceId);
        }

        if ($marker->diskId !== $disk->id) {
            return $this->unhealthy($disk, DiskHealthReason::MarkerMismatch, $deviceId);
        }

        if ($marker->kind !== $disk->kind) {
            return $this->unhealthy($disk, DiskHealthReason::MarkerKindMismatch, $deviceId);
        }

        if (! $this->filesystem->isDirectory($incomingDirectory)) {
            return $this->unhealthy($disk, DiskHealthReason::IncomingMissing, $deviceId);
        }

        $reasons = [];

        if (! $this->filesystem->isReadable($incomingDirectory)) {
            $reasons[] = DiskHealthReason::IncomingUnreadable;
        }

        if (! $this->filesystem->isWritable($incomingDirectory)) {
            $reasons[] = DiskHealthReason::IncomingUnwritable;
        }

        if ($reasons !== []) {
            return $this->status($disk, false, false, null, null, null, $reasons, $deviceId);
        }

        $capacity = $this->filesystem->capacity($resolvedRoot);

        if (! $this->filesystem->probe($incomingDirectory)) {
            $reasons[] = DiskHealthReason::ProbeFailed;
        }

        if ($capacity === null) {
            $reasons[] = DiskHealthReason::CapacityUnavailable;

            return $this->status($disk, false, false, null, null, null, $reasons, $deviceId);
        }

        $usableBytes = max($capacity['free'] - $disk->safetyReserveBytes, 0);
        $healthy = $reasons === [];
        $eligible = $healthy && $capacity['free'] >= $disk->safetyReserveBytes;

        if ($healthy && ! $eligible) {
            $reasons[] = DiskHealthReason::BelowSafetyReserve;
        }

        return $this->status(
            $disk,
            $healthy,
            $eligible,
            $capacity['total'],
            $capacity['free'],
            $usableBytes,
            $reasons,
            $deviceId,
        );
    }

    private function unhealthy(
        ConfiguredMediaDisk $disk,
        DiskHealthReason $reason,
        ?int $deviceId = null,
    ): DiskHealthStatus {
        return $this->status($disk, false, false, null, null, null, [$reason], $deviceId);
    }

    /**
     * @param  list<DiskHealthReason>  $reasons
     */
    private function status(
        ConfiguredMediaDisk $disk,
        bool $healthy,
        bool $eligible,
        ?int $totalBytes,
        ?int $freeBytes,
        ?int $usableBytes,
        array $reasons,
        ?int $deviceId,
    ): DiskHealthStatus {
        return new DiskHealthStatus(
            id: $disk->id,
            label: $disk->label,
            kind: $disk->kind,
            healthy: $healthy,
            eligible: $eligible,
            totalBytes: $totalBytes,
            freeBytes: $freeBytes,
            safetyReserveBytes: $disk->safetyReserveBytes,
            usableBytes: $usableBytes,
            reasons: $reasons,
            deviceId: $deviceId,
        );
    }
}
