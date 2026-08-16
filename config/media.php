<?php

$diskList = (string) env('MEDIA_DISKS', '');
$diskIds = $diskList === '' ? [] : explode(',', $diskList);
$disks = [];

foreach ($diskIds as $diskId) {
    $normalizedDiskId = trim($diskId);
    $environmentSuffix = strtoupper($normalizedDiskId);

    $disks[] = [
        'id' => $normalizedDiskId,
        'label' => env("MEDIA_DISK_{$environmentSuffix}_LABEL"),
        'path' => env("MEDIA_DISK_{$environmentSuffix}_PATH"),
        'movies_path' => env("MEDIA_DISK_{$environmentSuffix}_MOVIES_PATH"),
        'series_path' => env("MEDIA_DISK_{$environmentSuffix}_SERIES_PATH"),
        'series_default_category' => env("MEDIA_DISK_{$environmentSuffix}_SERIES_DEFAULT_CATEGORY"),
        'reserve_gib' => env("MEDIA_DISK_{$environmentSuffix}_RESERVE_GIB"),
    ];
}

return [
    'disks' => $disks,
    'default_reserve_gib' => env('MEDIA_DEFAULT_RESERVE_GIB', '20'),
    'require_mountpoint' => env(
        'MEDIA_REQUIRE_MOUNTPOINT',
        env('APP_ENV', 'production') === 'production',
    ),
];
