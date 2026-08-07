<?php

namespace App\Support\Media;

use App\Models\Upload;

final readonly class UploadSessionPresenter
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private UploadConfiguration $configuration,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Upload $upload): array
    {
        $disk = $this->diskRegistry->find($upload->disk_id);
        $mediaFile = $upload->status->value === 'completed' ? $upload->mediaFile()->first() : null;
        $replacedMediaFile = $upload->replacesMediaFile()->first();

        return [
            'uuid' => $upload->uuid,
            'status' => $upload->status->value,
            'original_filename' => $upload->original_filename,
            'last_modified_milliseconds' => $upload->last_modified_milliseconds,
            'disk' => [
                'id' => $upload->disk_id,
                'label' => $disk?->label,
            ],
            'target_relative_path' => $upload->target_relative_path,
            'staging_relative_path' => $upload->staging_relative_path,
            'declared_bytes' => $upload->declared_size,
            'confirmed_bytes' => $upload->confirmed_offset,
            'expires_at' => $upload->expires_at?->toISOString(),
            'uploading_at' => $upload->uploading_at?->toISOString(),
            'paused_at' => $upload->paused_at?->toISOString(),
            'processing_at' => $upload->processing_at?->toISOString(),
            'completed_at' => $upload->completed_at?->toISOString(),
            'failed_at' => $upload->failed_at?->toISOString(),
            'cancelled_at' => $upload->cancelled_at?->toISOString(),
            'poll_interval_milliseconds' => $this->configuration->processingPollIntervalMilliseconds,
            'failure' => $upload->status->value === 'failed' ? [
                'code' => $upload->error_code,
                'detail' => $upload->error_detail,
                'can_retry' => UploadProcessingFailure::isRecoverable($upload->error_code),
                'can_discard' => ! $upload->mediaFile()->exists()
                    && ! ($upload->replaces_media_file_id !== null && $upload->processing_claim !== null),
            ] : null,
            'replacement' => $replacedMediaFile === null ? null : [
                'media_file_id' => $replacedMediaFile->getKey(),
                'disk' => [
                    'id' => $replacedMediaFile->disk_id,
                    'label' => $this->diskRegistry->find($replacedMediaFile->disk_id)?->label,
                ],
                'relative_path' => $replacedMediaFile->relative_path,
                'size_bytes' => $replacedMediaFile->size_bytes,
                'confirmed_at' => $upload->replacement_confirmed_at?->toISOString(),
                'method' => $replacedMediaFile->disk_id === $upload->disk_id
                    && $replacedMediaFile->relative_path === $upload->target_relative_path
                        ? 'atomic_same_path_swap'
                        : 'finalize_then_delete',
            ],
            'finalized' => $mediaFile === null ? null : [
                'disk' => [
                    'id' => $mediaFile->disk_id,
                    'label' => $disk?->label,
                ],
                'relative_path' => $mediaFile->relative_path,
                'size_bytes' => $mediaFile->size_bytes,
                'container' => $mediaFile->container,
                'duration_milliseconds' => $mediaFile->duration_milliseconds,
                'video' => $mediaFile->video_metadata,
                'audio' => $mediaFile->audio_metadata,
                'finalized_at' => $mediaFile->finalized_at->toISOString(),
            ],
            'created_at' => $upload->created_at?->toISOString(),
            'updated_at' => $upload->updated_at?->toISOString(),
        ];
    }
}
