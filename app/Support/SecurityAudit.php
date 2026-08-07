<?php

namespace App\Support;

use App\Models\MediaFile;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\TrackedMovieDeletionClaim;
use Illuminate\Support\Facades\Log;

final class SecurityAudit
{
    public static function administratorBootstrapped(User $user): void
    {
        self::write('administrator_bootstrapped', [
            'user_id' => $user->id,
        ]);
    }

    public static function loginSucceeded(User $user, ?string $ipAddress): void
    {
        self::write('login_succeeded', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
        ]);
    }

    public static function disabledAuthenticationRejected(User $user, ?string $ipAddress, string $source): void
    {
        self::write('disabled_authentication_rejected', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'source' => $source,
        ]);
    }

    public static function administratorRecovered(User $user, bool $wasReEnabled): void
    {
        self::write('administrator_recovered', [
            'user_id' => $user->id,
            'was_re_enabled' => $wasReEnabled,
        ]);
    }

    public static function initialCredentialReplaced(User $user, ?string $ipAddress): void
    {
        self::write('initial_credential_replaced', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
        ]);
    }

    public static function mediaReplacementConfirmed(Upload $upload): void
    {
        self::write('media_replacement_confirmed', [
            'upload_id' => $upload->id,
            'user_id' => $upload->user_id,
            'media_item_id' => $upload->media_item_id,
            'replaces_media_file_id' => $upload->replaces_media_file_id,
            'target_disk_id' => $upload->disk_id,
        ]);
    }

    public static function mediaReplacementCompleted(Upload $upload, MediaFile $mediaFile): void
    {
        self::write('media_replacement_completed', [
            'upload_id' => $upload->id,
            'user_id' => $upload->user_id,
            'media_item_id' => $upload->media_item_id,
            'replaced_media_file_id' => $upload->replaces_media_file_id,
            'new_media_file_id' => $mediaFile->id,
            'target_disk_id' => $mediaFile->disk_id,
        ]);
    }

    public static function movieDeletionConfirmed(TrackedMovieDeletionClaim $claim, User $actor): void
    {
        self::write('movie_deletion_confirmed', [
            'user_id' => $actor->id,
            'media_item_id' => $claim->mediaItemId,
            'media_file_id' => $claim->mediaFileId,
            'source_upload_id' => $claim->sourceUploadId,
            'disk_id' => $claim->diskId,
            'size_bytes' => $claim->sizeBytes,
        ]);
    }

    public static function movieDeletionCompleted(TrackedMovieDeletionClaim $claim, User $actor): void
    {
        self::write('movie_deletion_completed', [
            'user_id' => $actor->id,
            'media_item_id' => $claim->mediaItemId,
            'media_file_id' => $claim->mediaFileId,
            'source_upload_id' => $claim->sourceUploadId,
            'disk_id' => $claim->diskId,
            'size_bytes' => $claim->sizeBytes,
        ]);
    }

    /**
     * @param  array<string, bool|int|string|null>  $context
     */
    private static function write(string $event, array $context): void
    {
        Log::notice('security.audit', [
            'event' => $event,
            ...$context,
        ]);
    }
}
