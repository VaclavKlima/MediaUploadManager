<?php

namespace App\Support\Media;

use App\Enums\UploadStatus;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;

final readonly class UploadCapacityPlanner
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MovieUploadConflictChecker $conflictChecker,
    ) {}

    public function plan(
        MediaItem $mediaItem,
        CanonicalMoviePath $path,
        int $declaredSize,
        ?User $actor = null,
    ): UploadCapacityPlan {
        $conflicts = $this->conflictChecker->check($mediaItem, $path, $actor);
        $reservedBytes = $this->activeReservedBytes();
        $conflictDisks = collect($conflicts->disks)->keyBy('id');
        $disks = $this->diskRegistry->all();
        usort($disks, fn (ConfiguredMediaDisk $left, ConfiguredMediaDisk $right): int => $left->id <=> $right->id);

        $plans = [];

        foreach ($disks as $disk) {
            $health = $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint());
            $conflict = $conflictDisks->get($disk->id);
            $activeReservedBytes = $reservedBytes[$disk->id] ?? 0;
            $projection = $health->usableBytes === null
                ? null
                : new CapacityProjection($health->usableBytes, $activeReservedBytes, $declaredSize);
            $projectedUsableBytes = $projection?->projectedBytes;
            $status = match (true) {
                $conflict?->status === 'conflict' => 'conflict',
                ! $health->healthy || $conflict?->status === 'unavailable' => 'unavailable',
                $conflict?->status === 'replaceable' => 'replaceable',
                default => 'clear',
            };
            $eligible = (($conflicts->canStartNewUpload && $status === 'clear')
                || ($conflicts->canReplaceCurrentPrimary && $status === 'replaceable'))
                && $health->eligible
                && $projectedUsableBytes !== null
                && $projectedUsableBytes >= 0;
            $replacementMethod = $status === 'replaceable' && $conflicts->replaceable !== null
                ? ($conflicts->replaceable->diskId === $disk->id
                    && $conflicts->replaceable->relativePath === $path->relativePath
                        ? 'atomic_same_path_swap'
                        : 'finalize_then_delete')
                : null;

            $plans[] = new DiskCapacityPlan(
                id: $disk->id,
                label: $disk->label,
                status: $status,
                healthy: $health->healthy,
                totalBytes: $health->totalBytes,
                freeBytes: $health->freeBytes,
                safetyReserveBytes: $health->safetyReserveBytes,
                usableBytes: $health->usableBytes,
                activeReservedBytes: $activeReservedBytes,
                projectedUsableBytes: $projectedUsableBytes,
                eligible: $eligible,
                replacementMethod: $replacementMethod,
                reasons: $this->reasons($conflicts, $conflict, $health, $projectedUsableBytes),
            );
        }

        $recommended = collect($plans)
            ->filter(fn (DiskCapacityPlan $disk): bool => $disk->eligible)
            ->sort(function (DiskCapacityPlan $left, DiskCapacityPlan $right): int {
                $capacityComparison = ($right->projectedUsableBytes ?? PHP_INT_MIN)
                    <=> ($left->projectedUsableBytes ?? PHP_INT_MIN);

                return $capacityComparison !== 0 ? $capacityComparison : $left->id <=> $right->id;
            })
            ->first();

        return new UploadCapacityPlan(
            declaredSize: $declaredSize,
            canStartNewUpload: $conflicts->canStartNewUpload && $recommended instanceof DiskCapacityPlan,
            canReplaceCurrentPrimary: $conflicts->canReplaceCurrentPrimary && $recommended instanceof DiskCapacityPlan,
            replaceable: $conflicts->replaceable,
            blockers: $conflicts->blockers,
            disks: $plans,
            recommendedDiskId: $recommended?->id,
        );
    }

    /** @return array<string, int> */
    private function activeReservedBytes(): array
    {
        $reservedBytes = [];
        $uploads = Upload::query()
            ->whereIn('status', UploadStatus::capacityReservingValues())
            ->get(['disk_id', 'declared_size', 'confirmed_offset']);

        foreach ($uploads as $upload) {
            $remainingBytes = max($upload->declared_size - $upload->confirmed_offset, 0);
            $currentBytes = $reservedBytes[$upload->disk_id] ?? 0;
            $reservedBytes[$upload->disk_id] = $this->safeAdd($currentBytes, $remainingBytes);
        }

        return $reservedBytes;
    }

    private function safeAdd(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function reasons(
        MovieUploadConflictReport $report,
        ?MovieDiskTargetStatus $conflict,
        DiskHealthStatus $health,
        ?int $projectedUsableBytes,
    ): array {
        $reasons = $conflict?->toArray()['reasons'] ?? [];

        foreach ($health->toArray()['reasons'] as $reason) {
            $reasons[] = $reason;
        }

        if (! $report->canStartNewUpload && ! $report->canReplaceCurrentPrimary && $reasons === []) {
            $reasons[] = [
                'code' => 'global_conflict',
                'message' => 'Another movie or upload state blocks admission on every disk.',
            ];
        }

        if ($projectedUsableBytes !== null && $projectedUsableBytes < 0) {
            $reasons[] = [
                'code' => 'insufficient_capacity',
                'message' => 'The file would exceed currently reservable capacity.',
            ];
        }

        return array_values(collect($reasons)
            ->unique('code')
            ->values()
            ->all());
    }
}
