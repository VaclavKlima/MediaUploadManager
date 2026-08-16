<?php

namespace App\Actions\Series;

use App\Enums\UploadStatus;
use App\Models\SeriesUploadBatch;
use App\Models\Upload;
use App\Support\Media\Exceptions\UploadTransportException;
use Illuminate\Support\Facades\DB;

class RecoverSeriesBatch
{
    /**
     * @param  list<array{upload_uuid:string,source_identity:string,filename:string,declared_size:int,last_modified_milliseconds:int|null,fingerprint_first_sha256:string,fingerprint_last_sha256:string}>  $items
     */
    public function execute(SeriesUploadBatch $batch, array $items): SeriesUploadBatch
    {
        return DB::transaction(function () use ($batch, $items): SeriesUploadBatch {
            $lockedBatch = SeriesUploadBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $uploads = $lockedBatch->uploads()
                ->whereIn('status', [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused])
                ->lockForUpdate()
                ->get();

            if ($uploads->count() !== count($items)) {
                throw new UploadTransportException(
                    'series_recovery_set_mismatch',
                    'Select every episode file that still needs transfer.',
                    422,
                );
            }

            $submittedByUuid = collect($items)->keyBy('upload_uuid');

            foreach ($uploads as $upload) {
                $submitted = $submittedByUuid->get($upload->uuid);
                $manifestItem = $lockedBatch->manifest[($upload->batch_position ?? 1) - 1] ?? null;

                if (! is_array($submitted)
                    || ! is_array($manifestItem)
                    || ($manifestItem['source_identity'] ?? null) !== $submitted['source_identity']
                    || ! $this->matches($upload, $submitted)
                ) {
                    throw new UploadTransportException(
                        'series_recovery_fingerprint_mismatch',
                        'One or more selected files do not exactly match this batch.',
                        422,
                    );
                }
            }

            return $lockedBatch->load('series', 'uploads.seriesEpisode.season', 'uploads.replacesMediaFile', 'uploads.mediaFile');
        }, attempts: 3);
    }

    /** @param array{filename:string,declared_size:int,last_modified_milliseconds:int|null,fingerprint_first_sha256:string,fingerprint_last_sha256:string} $submitted */
    private function matches(Upload $upload, array $submitted): bool
    {
        return $upload->original_filename === $submitted['filename']
            && $upload->declared_size === $submitted['declared_size']
            && $upload->last_modified_milliseconds === $submitted['last_modified_milliseconds']
            && hash_equals($upload->fingerprint_first_sha256, $submitted['fingerprint_first_sha256'])
            && hash_equals($upload->fingerprint_last_sha256, $submitted['fingerprint_last_sha256']);
    }
}
