<?php

namespace App\Support;

use App\Models\EpisodeRenameOperation;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\MediaItemReidentification;
use App\Models\SeriesDeletionOperation;
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

    public static function movieReidentificationConfirmed(
        MediaItemReidentification $operation,
        User $actor,
    ): void {
        $oldTmdbId = $operation->old_metadata_snapshot['tmdb_id'] ?? null;
        $newTmdbId = $operation->new_metadata_snapshot['tmdb_id'] ?? null;

        self::write('movie_reidentification_confirmed', [
            'user_id' => $actor->id,
            'media_item_id' => $operation->media_item_id,
            'media_item_reidentification_id' => $operation->id,
            'source_media_file_id' => $operation->source_media_file_id,
            'disk_id' => $operation->disk_id,
            'size_bytes' => $operation->size_bytes,
            'old_tmdb_id' => is_int($oldTmdbId) ? $oldTmdbId : null,
            'new_tmdb_id' => is_int($newTmdbId) ? $newTmdbId : null,
        ]);
    }

    public static function movieReidentificationCompleted(
        MediaItemReidentification $operation,
        User $actor,
    ): void {
        $oldTmdbId = $operation->old_metadata_snapshot['tmdb_id'] ?? null;
        $newTmdbId = $operation->new_metadata_snapshot['tmdb_id'] ?? null;

        self::write('movie_reidentification_completed', [
            'user_id' => $actor->id,
            'media_item_id' => $operation->media_item_id,
            'media_item_reidentification_id' => $operation->id,
            'source_media_file_id' => $operation->source_media_file_id,
            'disk_id' => $operation->disk_id,
            'size_bytes' => $operation->size_bytes,
            'old_tmdb_id' => is_int($oldTmdbId) ? $oldTmdbId : null,
            'new_tmdb_id' => is_int($newTmdbId) ? $newTmdbId : null,
        ]);
    }

    public static function episodeRenameConfirmed(EpisodeRenameOperation $operation, User $actor): void
    {
        self::write('episode_rename_confirmed', [
            'user_id' => $actor->id,
            'series_episode_id' => $operation->series_episode_id,
            'episode_rename_operation_id' => $operation->id,
            'source_media_file_id' => $operation->source_media_file_id,
            'disk_id' => $operation->disk_id,
            'size_bytes' => $operation->size_bytes,
        ]);
    }

    public static function episodeRenameCompleted(EpisodeRenameOperation $operation, User $actor): void
    {
        self::write('episode_rename_completed', [
            'user_id' => $actor->id,
            'series_episode_id' => $operation->series_episode_id,
            'episode_rename_operation_id' => $operation->id,
            'source_media_file_id' => $operation->source_media_file_id,
            'disk_id' => $operation->disk_id,
            'size_bytes' => $operation->size_bytes,
        ]);
    }

    public static function seriesDeletionConfirmed(SeriesDeletionOperation $operation, User $actor): void
    {
        self::write('series_media_deletion_confirmed', [
            'user_id' => $actor->id,
            'series_deletion_operation_id' => $operation->id,
            'series_id' => $operation->series_id,
            'scope_type' => $operation->scope_type,
            'scope_id' => $operation->scope_id,
            'file_count' => $operation->file_count,
            'total_size_bytes' => $operation->total_size_bytes,
        ]);
    }

    public static function seriesDeletionCompleted(SeriesDeletionOperation $operation, User $actor): void
    {
        self::write('series_media_deletion_completed', [
            'user_id' => $actor->id,
            'series_deletion_operation_id' => $operation->id,
            'series_id' => $operation->series_id,
            'scope_type' => $operation->scope_type,
            'scope_id' => $operation->scope_id,
            'file_count' => $operation->file_count,
            'total_size_bytes' => $operation->total_size_bytes,
        ]);
    }

    public static function libraryImportConfirmed(LibraryFinding $finding, User $actor, string $destination): void
    {
        self::write('library_import_confirmed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'disk_id' => $finding->disk_id,
            'source_relative_path' => $finding->relative_path,
            'destination_relative_path' => $destination,
        ]);
    }

    public static function libraryImportCompleted(LibraryFinding $finding, MediaFile $mediaFile, User $actor): void
    {
        self::write('library_import_completed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'media_file_id' => $mediaFile->id,
            'disk_id' => $mediaFile->disk_id,
        ]);
    }

    public static function libraryRelocationConfirmed(
        LibraryFinding $finding,
        LibraryFinding $missingFinding,
        User $actor,
        string $destination,
    ): void {
        self::write('library_relocation_confirmed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'missing_finding_id' => $missingFinding->id,
            'media_file_id' => $missingFinding->media_file_id,
            'disk_id' => $finding->disk_id,
            'destination_relative_path' => $destination,
        ]);
    }

    public static function libraryRelocationCompleted(
        LibraryFinding $finding,
        MediaFile $mediaFile,
        User $actor,
    ): void {
        self::write('library_relocation_completed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'missing_finding_id' => $finding->paired_missing_finding_id,
            'media_file_id' => $mediaFile->id,
            'disk_id' => $mediaFile->disk_id,
        ]);
    }

    public static function libraryFileDeletionConfirmed(LibraryFinding $finding, User $actor): void
    {
        self::write('library_file_deletion_confirmed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'disk_id' => $finding->disk_id,
            'size_bytes' => $finding->size_bytes,
        ]);
    }

    public static function libraryFileDeletionCompleted(LibraryFinding $finding, User $actor): void
    {
        self::write('library_file_deletion_completed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'disk_id' => $finding->disk_id,
            'size_bytes' => $finding->size_bytes,
        ]);
    }

    public static function folderCleanupConfirmed(FolderCleanup $cleanup, User $actor): void
    {
        self::write('folder_cleanup_confirmed', [
            'user_id' => $actor->id,
            'folder_cleanup_id' => $cleanup->id,
            'disk_id' => $cleanup->disk_id,
            'file_count' => $cleanup->file_count,
            'total_size_bytes' => $cleanup->total_size_bytes,
        ]);
    }

    public static function folderCleanupCompleted(FolderCleanup $cleanup, User $actor): void
    {
        self::write('folder_cleanup_completed', [
            'user_id' => $actor->id,
            'folder_cleanup_id' => $cleanup->id,
            'disk_id' => $cleanup->disk_id,
            'file_count' => $cleanup->file_count,
            'total_size_bytes' => $cleanup->total_size_bytes,
        ]);
    }

    public static function externalMediaRemovalConfirmed(
        LibraryFinding $finding,
        MediaFile $mediaFile,
        User $actor,
    ): void {
        self::write('external_media_removal_confirmed', [
            'user_id' => $actor->id,
            'library_finding_id' => $finding->id,
            'media_file_id' => $mediaFile->id,
            'disk_id' => $mediaFile->disk_id,
        ]);
    }

    public static function failedJobRetryRequested(string $failedJobUuid, User $actor): void
    {
        self::write('failed_job_retry_requested', [
            'user_id' => $actor->id,
            'failed_job_uuid' => $failedJobUuid,
        ]);
    }

    public static function failedJobRetryCompleted(
        string $failedJobUuid,
        User $actor,
        bool $succeeded,
        string $outcome,
    ): void {
        self::write('failed_job_retry_completed', [
            'user_id' => $actor->id,
            'failed_job_uuid' => $failedJobUuid,
            'succeeded' => $succeeded,
            'outcome' => $outcome,
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
