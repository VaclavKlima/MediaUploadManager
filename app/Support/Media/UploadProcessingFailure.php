<?php

namespace App\Support\Media;

final class UploadProcessingFailure
{
    /** @var list<string> */
    private const RECOVERABLE_CODES = [
        'media_probe_timeout',
        'media_probe_unavailable',
        'tus_verification_unavailable',
        'media_disk_unavailable',
        'media_finalization_busy',
        'target_directory_unavailable',
        'media_promotion_unavailable',
        'staging_unlink_failed',
        'replacement_swap_failed',
        'replacement_delete_failed',
        'media_processing_interrupted',
    ];

    public static function isRecoverable(?string $errorCode): bool
    {
        return is_string($errorCode) && in_array($errorCode, self::RECOVERABLE_CODES, true);
    }
}
