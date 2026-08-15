<?php

use App\Enums\MediaRootKind;
use App\Support\Media\DashboardFilesystemGrouper;
use App\Support\Media\DiskHealthReason;
use App\Support\Media\DiskHealthStatus;

/**
 * @param  list<DiskHealthReason>  $reasons
 */
function dashboardRootStatus(
    string $id,
    string $label,
    MediaRootKind $kind,
    ?int $deviceId,
    ?int $totalBytes,
    ?int $freeBytes,
    int $reserveBytes,
    bool $healthy = true,
    bool $eligible = true,
    array $reasons = [],
): DiskHealthStatus {
    return new DiskHealthStatus(
        id: $id,
        label: $label,
        kind: $kind,
        healthy: $healthy,
        eligible: $eligible,
        totalBytes: $totalBytes,
        freeBytes: $freeBytes,
        safetyReserveBytes: $reserveBytes,
        usableBytes: $freeBytes === null ? null : max($freeBytes - $reserveBytes, 0),
        reasons: $reasons,
        deviceId: $deviceId,
    );
}

it('groups roots by device in configured order without summing shared capacity or reserves', function () {
    $volumes = (new DashboardFilesystemGrouper)->group([
        dashboardRootStatus('primary', 'Primary', MediaRootKind::Movies, 100, 1_000, 700, 100),
        dashboardRootStatus('primary', 'Primary', MediaRootKind::Series, 100, 900, 650, 100),
        dashboardRootStatus('archive', 'Archive', MediaRootKind::Movies, 100, 1_100, 600, 200),
        dashboardRootStatus('external', 'External', MediaRootKind::Movies, 200, 2_000, 1_500, 300),
    ]);

    expect($volumes)->toHaveCount(2)
        ->and($volumes[0]['id'])->toBe('storage-volume-1')
        ->and($volumes[0]['label'])->toBe('Storage volume 1')
        ->and($volumes[0]['total_bytes'])->toBe(900)
        ->and($volumes[0]['free_bytes'])->toBe(600)
        ->and($volumes[0]['disks'])->toHaveCount(2)
        ->and($volumes[0]['disks'][0]['id'])->toBe('primary')
        ->and($volumes[0]['disks'][0]['safety_reserve_bytes'])->toBe(100)
        ->and($volumes[0]['disks'][0]['usable_bytes'])->toBe(500)
        ->and($volumes[0]['disks'][0]['roots'])->toHaveCount(2)
        ->and($volumes[0]['disks'][1]['id'])->toBe('archive')
        ->and($volumes[0]['disks'][1]['safety_reserve_bytes'])->toBe(200)
        ->and($volumes[0]['disks'][1]['usable_bytes'])->toBe(400)
        ->and($volumes[1]['id'])->toBe('storage-volume-2')
        ->and($volumes[1]['disks'][0]['id'])->toBe('external');

    expect(json_encode($volumes))->not->toContain('device');
});

it('aggregates root health and eligibility conservatively', function () {
    $volumes = (new DashboardFilesystemGrouper)->group([
        dashboardRootStatus('media', 'Media', MediaRootKind::Movies, 100, 1_000, 700, 100),
        dashboardRootStatus(
            'media',
            'Media',
            MediaRootKind::Series,
            100,
            null,
            null,
            100,
            healthy: false,
            eligible: false,
            reasons: [DiskHealthReason::MarkerMissing],
        ),
    ]);

    expect($volumes[0]['health'])->toBe('unhealthy')
        ->and($volumes[0]['eligible'])->toBeFalse()
        ->and($volumes[0]['total_bytes'])->toBe(1_000)
        ->and($volumes[0]['free_bytes'])->toBe(700)
        ->and($volumes[0]['disks'][0]['health'])->toBe('unhealthy')
        ->and($volumes[0]['disks'][0]['eligible'])->toBeFalse()
        ->and($volumes[0]['disks'][0]['usable_bytes'])->toBe(600)
        ->and($volumes[0]['disks'][0]['roots'][1])->toMatchArray([
            'kind' => 'series',
            'health' => 'unhealthy',
            'eligible' => false,
            'reasons' => [[
                'code' => DiskHealthReason::MarkerMissing->value,
                'message' => DiskHealthReason::MarkerMissing->message(),
            ]],
        ]);
});

it('attaches one unidentified sibling to a known device and isolates ambiguous fallbacks', function () {
    $volumes = (new DashboardFilesystemGrouper)->group([
        dashboardRootStatus('media', 'Media', MediaRootKind::Movies, null, null, null, 100, false, false),
        dashboardRootStatus('media', 'Media', MediaRootKind::Series, 100, 1_000, 700, 100),
        dashboardRootStatus('unknown', 'Unknown', MediaRootKind::Movies, null, null, null, 0, false, false),
        dashboardRootStatus('unknown', 'Unknown', MediaRootKind::Series, null, null, null, 0, false, false),
        dashboardRootStatus('split', 'Split', MediaRootKind::Movies, 200, 2_000, 1_500, 0),
        dashboardRootStatus('split', 'Split', MediaRootKind::Series, 300, 3_000, 2_500, 0),
        dashboardRootStatus('split', 'Split', MediaRootKind::Movies, null, null, null, 0, false, false),
    ]);

    expect($volumes)->toHaveCount(6)
        ->and($volumes[0]['disks'][0]['id'])->toBe('media')
        ->and($volumes[0]['disks'][0]['roots'])->toHaveCount(2)
        ->and($volumes[1]['disks'][0]['id'])->toBe('unknown')
        ->and($volumes[1]['disks'][0]['roots'])->toHaveCount(1)
        ->and($volumes[2]['disks'][0]['id'])->toBe('unknown')
        ->and($volumes[2]['disks'][0]['roots'])->toHaveCount(1)
        ->and($volumes[3]['total_bytes'])->toBe(2_000)
        ->and($volumes[4]['total_bytes'])->toBe(3_000)
        ->and($volumes[5]['total_bytes'])->toBeNull()
        ->and(array_column($volumes, 'label'))->toBe([
            'Storage volume 1',
            'Storage volume 2',
            'Storage volume 3',
            'Storage volume 4',
            'Storage volume 5',
            'Storage volume 6',
        ]);
});
