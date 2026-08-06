<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Paused = 'paused';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function reservesCapacity(): bool
    {
        return match ($this) {
            self::Pending, self::Uploading, self::Paused, self::Processing => true,
            self::Completed, self::Failed, self::Cancelled, self::Expired => false,
        };
    }

    public function mayTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Uploading, self::Cancelled, self::Expired], true),
            self::Uploading => in_array($target, [self::Paused, self::Processing, self::Cancelled, self::Expired, self::Failed], true),
            self::Paused => in_array($target, [self::Uploading, self::Cancelled, self::Expired, self::Failed], true),
            self::Processing => in_array($target, [self::Completed, self::Failed], true),
            self::Failed => $target === self::Processing,
            self::Completed, self::Cancelled, self::Expired => false,
        };
    }
}
