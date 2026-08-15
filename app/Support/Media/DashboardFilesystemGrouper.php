<?php

namespace App\Support\Media;

/**
 * @phpstan-type RootSummary array{
 *     kind: string,
 *     health: 'healthy'|'unhealthy',
 *     eligible: bool,
 *     reasons: list<array{code: string, message: string}>
 * }
 * @phpstan-type DiskSummary array{
 *     id: string,
 *     label: string,
 *     health: 'healthy'|'unhealthy',
 *     eligible: bool,
 *     safety_reserve_bytes: int,
 *     usable_bytes: int|null,
 *     roots: list<RootSummary>
 * }
 * @phpstan-type VolumeSummary array{
 *     id: string,
 *     label: string,
 *     health: 'healthy'|'unhealthy',
 *     eligible: bool,
 *     total_bytes: int|null,
 *     free_bytes: int|null,
 *     disks: list<DiskSummary>
 * }
 */
final readonly class DashboardFilesystemGrouper
{
    /**
     * @param  list<DiskHealthStatus>  $rootStatuses
     * @return list<VolumeSummary>
     */
    public function group(array $rootStatuses): array
    {
        $identifiedDevicesByDisk = [];

        foreach ($rootStatuses as $status) {
            if ($status->deviceId !== null) {
                $identifiedDevicesByDisk[$status->id][$status->deviceId] = true;
            }
        }

        $groups = [];

        foreach ($rootStatuses as $index => $status) {
            $groupKey = $this->groupKey($status, $index, $identifiedDevicesByDisk);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'first_index' => $index,
                    'statuses' => [],
                ];
            }

            $groups[$groupKey]['first_index'] = min($groups[$groupKey]['first_index'], $index);
            $groups[$groupKey]['statuses'][] = $status;
        }

        $orderedGroups = array_values($groups);

        usort(
            $orderedGroups,
            fn (array $first, array $second): int => $first['first_index'] <=> $second['first_index'],
        );

        return array_map(
            fn (array $group, int $index): array => $this->summarizeVolume(
                $group['statuses'],
                $index + 1,
            ),
            $orderedGroups,
            array_keys($orderedGroups),
        );
    }

    /**
     * @param  array<string, array<int, true>>  $identifiedDevicesByDisk
     */
    private function groupKey(
        DiskHealthStatus $status,
        int $index,
        array $identifiedDevicesByDisk,
    ): string {
        if ($status->deviceId !== null) {
            return 'device:'.$status->deviceId;
        }

        $siblingDevices = array_keys($identifiedDevicesByDisk[$status->id] ?? []);

        if (count($siblingDevices) === 1) {
            return 'device:'.$siblingDevices[0];
        }

        return 'unidentified:'.$index;
    }

    /**
     * @param  list<DiskHealthStatus>  $statuses
     * @return VolumeSummary
     */
    private function summarizeVolume(array $statuses, int $number): array
    {
        $totalBytes = $this->lowestAvailable(array_map(
            fn (DiskHealthStatus $status): ?int => $status->totalBytes,
            $statuses,
        ));
        $freeBytes = $this->lowestAvailable(array_map(
            fn (DiskHealthStatus $status): ?int => $status->freeBytes,
            $statuses,
        ));
        $statusesByDisk = [];

        foreach ($statuses as $status) {
            $statusesByDisk[$status->id][] = $status;
        }

        $disks = array_map(
            fn (array $diskStatuses): array => $this->summarizeDisk($diskStatuses, $freeBytes),
            array_values($statusesByDisk),
        );

        return [
            'id' => 'storage-volume-'.$number,
            'label' => 'Storage volume '.$number,
            'health' => $this->allHealthy($statuses) ? 'healthy' : 'unhealthy',
            'eligible' => $this->allEligible($statuses),
            'total_bytes' => $totalBytes,
            'free_bytes' => $freeBytes,
            'disks' => $disks,
        ];
    }

    /**
     * @param  non-empty-list<DiskHealthStatus>  $statuses
     * @return DiskSummary
     */
    private function summarizeDisk(array $statuses, ?int $freeBytes): array
    {
        $first = $statuses[0];

        return [
            'id' => $first->id,
            'label' => $first->label,
            'health' => $this->allHealthy($statuses) ? 'healthy' : 'unhealthy',
            'eligible' => $this->allEligible($statuses),
            'safety_reserve_bytes' => $first->safetyReserveBytes,
            'usable_bytes' => $freeBytes === null
                ? null
                : max($freeBytes - $first->safetyReserveBytes, 0),
            'roots' => array_map(
                fn (DiskHealthStatus $status): array => [
                    'kind' => $status->kind->value,
                    'health' => $status->healthy ? 'healthy' : 'unhealthy',
                    'eligible' => $status->eligible,
                    'reasons' => array_map(
                        fn (DiskHealthReason $reason): array => [
                            'code' => $reason->value,
                            'message' => $reason->message(),
                        ],
                        $status->reasons,
                    ),
                ],
                $statuses,
            ),
        ];
    }

    /** @param list<int|null> $values */
    private function lowestAvailable(array $values): ?int
    {
        $available = array_values(array_filter(
            $values,
            fn (?int $value): bool => $value !== null,
        ));

        return $available === [] ? null : min($available);
    }

    /** @param list<DiskHealthStatus> $statuses */
    private function allHealthy(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if (! $status->healthy) {
                return false;
            }
        }

        return true;
    }

    /** @param list<DiskHealthStatus> $statuses */
    private function allEligible(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if (! $status->eligible) {
                return false;
            }
        }

        return true;
    }
}
