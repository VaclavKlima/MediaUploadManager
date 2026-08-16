<?php

namespace App\Support\Media;

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\UploadTransportException;
use Illuminate\Support\Facades\DB;

final readonly class TusUploadReconciler
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private TusTransportClient $transportClient,
        private TransitionUploadStatus $transitionUploadStatus,
        private TusUploadTokenIssuer $tokenIssuer,
        private UploadConfiguration $configuration,
    ) {}

    public function stagingPath(Upload $upload): string
    {
        $disk = $this->diskRegistry->findRoot($upload->disk_id, $upload->root_kind);

        if ($disk === null) {
            throw $this->unsafeState('upload_disk_unavailable');
        }

        $health = $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint());

        if (! $health->healthy) {
            throw $this->unsafeState('upload_disk_unavailable');
        }

        try {
            return $this->pathGuard->resolveChild($disk->root, $upload->staging_relative_path);
        } catch (Exceptions\MediaPathException $exception) {
            throw $this->unsafeState('upload_path_unsafe', $exception);
        }
    }

    public function reconcile(Upload $upload): Upload
    {
        $stagingPath = $this->stagingPath($upload);
        $hasCreationIdentity = $upload->tus_resource_id !== null
            || $upload->tus_creation_claimed_at !== null;

        if (! $hasCreationIdentity) {
            if ($upload->status !== UploadStatus::Pending) {
                throw $this->unsafeState('upload_state_inconsistent');
            }

            return $upload->refresh();
        }

        $remote = $this->transportClient->head($upload);

        if ($remote === null) {
            throw $this->unsafeState('upload_resource_missing');
        }

        $physicalSize = $this->filesystem->fileSize($stagingPath);

        if ($remote['length'] !== $upload->declared_size
            || $remote['offset'] > $upload->declared_size
            || $physicalSize === null
            || $physicalSize !== $remote['offset']
        ) {
            throw $this->unsafeState('upload_state_inconsistent');
        }

        $reconciledUpload = DB::transaction(function () use ($upload, $remote): Upload {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($lockedUpload->status, [UploadStatus::Cancelled, UploadStatus::Expired], true)) {
                return $lockedUpload;
            }

            if ($lockedUpload->tus_resource_id === null) {
                $lockedUpload->update([
                    'tus_resource_id' => $lockedUpload->uuid,
                    'tus_created_at' => $lockedUpload->tus_created_at ?? now(),
                ]);
            }

            $lockedUpload->update([
                'confirmed_offset' => max($lockedUpload->confirmed_offset, $remote['offset']),
                'last_activity_at' => now(),
                'expires_at' => now()->addSeconds($this->configuration->inactivitySeconds),
            ]);

            if ($remote['offset'] === $lockedUpload->declared_size) {
                if (in_array($lockedUpload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true)) {
                    if ($lockedUpload->status === UploadStatus::Pending) {
                        $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Uploading);
                    }

                    $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Processing);
                }

                $this->tokenIssuer->revoke($lockedUpload);

                return $lockedUpload->refresh();
            }

            if ($lockedUpload->status === UploadStatus::Pending) {
                $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Uploading);
            }

            return $lockedUpload->refresh();
        }, attempts: 3);

        if ($reconciledUpload->status === UploadStatus::Processing) {
            ProcessCompletedUpload::dispatch($reconciledUpload->id);
        }

        return $reconciledUpload;
    }

    private function unsafeState(string $code, ?\Throwable $previous = null): UploadTransportException
    {
        return new UploadTransportException(
            $code,
            'The upload state could not be verified safely.',
            409,
            $previous,
        );
    }
}
