<?php

namespace App\Support\Series;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\SeriesUploadBatch;
use App\Models\Upload;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\UploadProcessingFailure;

final readonly class SeriesBatchPresenter
{
    public function __construct(private ConfiguredDiskRegistry $diskRegistry) {}

    /** @return array<string, mixed> */
    public function present(SeriesUploadBatch $batch): array
    {
        $batch->loadMissing('series', 'uploads.seriesEpisode.season', 'uploads.replacesMediaFile', 'uploads.mediaFile');
        $disk = $this->diskRegistry->findRoot($batch->disk_id, MediaRootKind::Series);
        $predecessorsAreResolved = true;
        $items = [];

        foreach ($batch->uploads as $upload) {
            $manifestItem = $batch->manifest[($upload->batch_position ?? 1) - 1] ?? [];
            $items[] = $this->item($upload, $manifestItem, $predecessorsAreResolved);
            $predecessorsAreResolved = $predecessorsAreResolved
                && in_array($upload->status, [UploadStatus::Completed, UploadStatus::Cancelled], true);
        }

        return [
            'uuid' => $batch->uuid,
            'status' => $batch->status->value,
            'series' => [
                'id' => $batch->series_id,
                'tmdb_id' => $batch->series->tmdb_id,
                'name' => $batch->series->name,
                'year' => $batch->series->first_air_year,
                'category' => $batch->series->category->value,
            ],
            'home_disk' => ['id' => $batch->disk_id, 'label' => $disk?->label],
            'declared_bytes' => $batch->declared_bytes,
            'confirmed_bytes' => $batch->uploads->sum('confirmed_offset'),
            'items' => $items,
            'started_at' => $batch->started_at?->toISOString(),
            'paused_at' => $batch->paused_at?->toISOString(),
            'completed_at' => $batch->completed_at?->toISOString(),
            'cancelled_at' => $batch->cancelled_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifestItem
     * @return array<string, mixed>
     */
    private function item(Upload $upload, array $manifestItem, bool $predecessorsAreResolved): array
    {
        $episode = $upload->seriesEpisode;
        $replacement = $upload->replacesMediaFile;
        $finalized = $upload->mediaFile;
        $canRetry = $upload->status === UploadStatus::Failed
            && UploadProcessingFailure::isRecoverable($upload->error_code)
            && $finalized === null;
        $canDiscard = $upload->status === UploadStatus::Failed
            && $finalized === null
            && ! ($upload->replaces_media_file_id !== null && $upload->processing_claim !== null);
        $canCancel = in_array($upload->status, [
            UploadStatus::Pending,
            UploadStatus::Uploading,
            UploadStatus::Paused,
            UploadStatus::Expired,
        ], true) || $canDiscard;

        return [
            'upload_uuid' => $upload->uuid,
            'position' => $upload->batch_position,
            'source_identity' => $manifestItem['source_identity'] ?? $upload->original_filename,
            'source_basename' => $upload->original_filename,
            'last_modified_milliseconds' => $upload->last_modified_milliseconds,
            'expires_at' => $upload->expires_at?->toISOString(),
            'expired_at' => $upload->expired_at?->toISOString(),
            'episode' => [
                'id' => $episode?->getKey(),
                'identity' => $episode === null ? null : sprintf('S%02dE%02d', $episode->season->season_number, $episode->episode_number),
                'title' => $episode?->name,
                'season_number' => $episode?->season->season_number,
                'episode_number' => $episode?->episode_number,
            ],
            'destination' => $upload->target_relative_path,
            'status' => $upload->status->value,
            'declared_bytes' => $upload->declared_size,
            'confirmed_bytes' => $upload->confirmed_offset,
            'failure' => $upload->status === UploadStatus::Failed ? [
                'code' => $upload->error_code,
                'detail' => $upload->error_detail,
                'can_retry' => $canRetry,
                'can_discard' => $canDiscard,
            ] : null,
            'replacement' => $replacement === null ? null : [
                'media_file_id' => $replacement->getKey(),
                'relative_path' => $replacement->relative_path,
                'size_bytes' => $replacement->size_bytes,
            ],
            'actions' => [
                'authorize' => $predecessorsAreResolved
                    && in_array($upload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true),
                'pause' => $upload->status === UploadStatus::Uploading,
                'retry' => $canRetry,
                'cancel' => $canCancel,
            ],
            'finalized' => $finalized === null ? null : [
                'relative_path' => $finalized->relative_path,
                'size_bytes' => $finalized->size_bytes,
                'container' => $finalized->container,
                'duration_milliseconds' => $finalized->duration_milliseconds,
                'finalized_at' => $finalized->finalized_at->toISOString(),
            ],
        ];
    }
}
