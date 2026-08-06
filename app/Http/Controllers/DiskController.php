<?php

namespace App\Http\Controllers;

use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\MediaDiskHealthChecker;
use Illuminate\Http\JsonResponse;

class DiskController extends Controller
{
    public function __invoke(
        ConfiguredDiskRegistry $diskRegistry,
        MediaDiskHealthChecker $healthChecker,
    ): JsonResponse {
        $disks = array_map(
            fn (ConfiguredMediaDisk $disk): array => $healthChecker
                ->check($disk, $diskRegistry->requiresMountpoint())
                ->toArray(),
            $diskRegistry->all(),
        );

        return response()->json(['data' => $disks]);
    }
}
