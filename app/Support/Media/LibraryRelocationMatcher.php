<?php

namespace App\Support\Media;

use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Support\Media\Exceptions\RelocationVerificationException;
use Illuminate\Support\Facades\DB;

class LibraryRelocationMatcher
{
    public function __construct(private readonly LibraryRelocationVerifier $verifier) {}

    public function matchScan(LibraryScan $scan): void
    {
        $missingByMediaItem = $scan->findings()
            ->where('kind', 'missing')
            ->where('status', 'missing')
            ->whereNull('resolved_at')
            ->whereNotNull('media_item_id')
            ->get()
            ->groupBy('media_item_id');

        $scan->findings()
            ->where('kind', 'discovered')
            ->whereNull('resolved_at')
            ->whereNotNull('tmdb_id')
            ->whereNotNull('destination_relative_path')
            ->orderBy('id')
            ->get()
            ->each(function (LibraryFinding $discovered) use ($missingByMediaItem): void {
                if ($discovered->media_item_id === null) {
                    return;
                }

                $candidates = $missingByMediaItem->get($discovered->media_item_id);

                if ($candidates === null || $candidates->count() !== 1) {
                    return;
                }

                $missing = $candidates->first();

                if (! $missing instanceof LibraryFinding || ! is_int($discovered->tmdb_id)) {
                    return;
                }

                try {
                    $this->verifier->prove($discovered, $missing, $discovered->tmdb_id);
                } catch (RelocationVerificationException) {
                    return;
                }

                DB::transaction(function () use ($discovered, $missing): void {
                    $lockedDiscovered = LibraryFinding::query()->whereKey($discovered)->lockForUpdate()->firstOrFail();
                    $lockedMissing = LibraryFinding::query()->whereKey($missing)->lockForUpdate()->firstOrFail();

                    if ($lockedDiscovered->resolved_at !== null
                        || $lockedMissing->resolved_at !== null
                        || $lockedMissing->status !== 'missing'
                        || $lockedDiscovered->operation_claim !== null
                    ) {
                        return;
                    }

                    $lockedDiscovered->update([
                        'media_item_id' => $lockedMissing->media_item_id,
                        'media_file_id' => $lockedMissing->media_file_id,
                        'paired_missing_finding_id' => $lockedMissing->id,
                        'status' => 'restore_ready',
                        'error_detail' => null,
                    ]);
                }, attempts: 3);
            });
    }
}
