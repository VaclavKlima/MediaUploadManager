<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaPathException;
use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\TusUploadTokenIssuer;
use App\Support\Media\UploadConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class DiscardFailedUpload
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private TransitionUploadStatus $transitionUploadStatus,
        private TusUploadTokenIssuer $tokenIssuer,
        private UploadConfiguration $configuration,
    ) {}

    public function execute(Upload $upload, User $actor): Upload
    {
        if ($upload->user_id !== $actor->getKey() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }

        if ($upload->status === UploadStatus::Cancelled) {
            return $upload->refresh();
        }

        if ($upload->status !== UploadStatus::Failed
            || ($upload->replaces_media_file_id !== null && $upload->processing_claim !== null)
            || MediaFile::query()->where('source_upload_id', $upload->getKey())->exists()
        ) {
            throw $this->forbidden();
        }

        $disk = $this->diskRegistry->find($upload->disk_id);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw new UploadTransportException(
                'upload_disk_unavailable',
                'The failed upload disk is temporarily unavailable.',
                503,
            );
        }

        try {
            $stagePath = $this->pathGuard->resolveChild($disk->root, $upload->staging_relative_path);
            $targetPath = $this->pathGuard->resolveChild($disk->root, $upload->target_relative_path);
        } catch (MediaPathException $exception) {
            throw new UploadTransportException(
                'upload_discard_unsafe',
                'The failed upload paths could not be verified safely.',
                409,
                $exception,
            );
        }

        if ($this->filesystem->pathExists($targetPath)) {
            throw $this->forbidden();
        }

        if ($this->filesystem->pathExists($stagePath)) {
            if ($this->filesystem->isSymbolicLink($stagePath)
                || ! $this->filesystem->isRegularFile($stagePath)
                || $this->filesystem->fileSize($stagePath) !== $upload->declared_size
                || ! $this->filesystem->deleteFile($stagePath)
            ) {
                throw $this->forbidden();
            }
        }

        $sidecarPath = $this->configuration->tusMetadataPath.'/'.$upload->uuid.'.info';

        if ($this->filesystem->pathExists($sidecarPath)
            && ($this->filesystem->isSymbolicLink($sidecarPath)
                || ! $this->filesystem->isRegularFile($sidecarPath)
                || ! $this->filesystem->deleteFile($sidecarPath))
        ) {
            throw $this->forbidden();
        }

        return DB::transaction(function () use ($upload): Upload {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUpload->status !== UploadStatus::Failed
                || ($lockedUpload->replaces_media_file_id !== null && $lockedUpload->processing_claim !== null)
                || MediaFile::query()->where('source_upload_id', $lockedUpload->getKey())->exists()
            ) {
                throw $this->forbidden();
            }

            $lockedUpload = $this->transitionUploadStatus->discardAsSystem($lockedUpload);
            $this->tokenIssuer->revoke($lockedUpload);

            return $lockedUpload->refresh();
        }, attempts: 3);
    }

    private function forbidden(): UploadTransportException
    {
        return new UploadTransportException(
            'upload_discard_forbidden',
            'This failed upload cannot be discarded because safe ownership of its bytes is not proven.',
        );
    }
}
