<?php

namespace App\Support\Media;

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\UploadTransportException;
use Illuminate\Support\Facades\DB;

final readonly class ExpireInactiveUploads
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaFilesystem $filesystem,
        private TusUploadReconciler $reconciler,
        private TusTransportClient $transportClient,
        private TusUploadTokenIssuer $tokenIssuer,
        private TransitionUploadStatus $transitionUploadStatus,
    ) {}

    /**
     * @return array{examined: int, expired: int, refreshed: int, processing: int, termination_requested: int, deferred: int}
     */
    public function execute(): array
    {
        $summary = [
            'examined' => 0,
            'expired' => 0,
            'refreshed' => 0,
            'processing' => 0,
            'termination_requested' => 0,
            'deferred' => 0,
        ];

        Upload::query()
            ->whereIn('status', [
                UploadStatus::Pending->value,
                UploadStatus::Uploading->value,
                UploadStatus::Paused->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->lazyById(100)
            ->each(function (Upload $upload) use (&$summary): void {
                $summary['examined']++;
                $result = $this->expire($upload);
                $summary[$result]++;
            });

        return $summary;
    }

    /** @return 'expired'|'refreshed'|'processing'|'termination_requested'|'deferred' */
    private function expire(Upload $candidate): string
    {
        $upload = DB::transaction(function () use ($candidate): ?Upload {
            $lockedUpload = Upload::query()->whereKey($candidate->getKey())->lockForUpdate()->first();

            if (! $lockedUpload instanceof Upload || ! $this->isDue($lockedUpload)) {
                return null;
            }

            if ($lockedUpload->tus_resource_id === null && $lockedUpload->tus_creation_claimed_at === null) {
                $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Expired);
                $this->tokenIssuer->revoke($lockedUpload);

                return $lockedUpload->refresh();
            }

            return $lockedUpload;
        }, attempts: 3);

        if (! $upload instanceof Upload) {
            return 'deferred';
        }

        if ($upload->status === UploadStatus::Expired) {
            return 'expired';
        }

        try {
            $stagingPath = $this->reconciler->stagingPath($upload);
            $remote = $this->transportClient->head($upload);

            if ($remote === null) {
                return 'deferred';
            }

            $disk = $this->diskRegistry->find($upload->disk_id);
            $physicalSize = $this->filesystem->fileSize($stagingPath);
            $rootDevice = $disk === null ? null : $this->filesystem->deviceId($disk->root);
            $stagingDevice = $this->filesystem->deviceId($stagingPath);

            if ($disk === null
                || ! $this->filesystem->isRegularFile($stagingPath)
                || $remote['length'] !== $upload->declared_size
                || $physicalSize === null
                || $physicalSize !== $remote['offset']
                || $rootDevice === null
                || $stagingDevice === null
                || $rootDevice !== $stagingDevice
                || $remote['offset'] < $upload->confirmed_offset
            ) {
                return 'deferred';
            }

            if ($remote['offset'] === $upload->declared_size || $remote['offset'] > $upload->confirmed_offset) {
                $reconciled = $this->reconciler->reconcile($upload);

                return $reconciled->status === UploadStatus::Processing ? 'processing' : 'refreshed';
            }

            $stillDue = DB::transaction(function () use ($upload): bool {
                $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->first();

                return $lockedUpload instanceof Upload
                    && $this->isDue($lockedUpload)
                    && $lockedUpload->confirmed_offset === $upload->confirmed_offset
                    && $lockedUpload->tus_resource_id === $upload->tus_resource_id
                    && $lockedUpload->getRawOriginal('expires_at') === $upload->getRawOriginal('expires_at')
                    && $lockedUpload->getRawOriginal('last_activity_at') === $upload->getRawOriginal('last_activity_at');
            }, attempts: 3);

            if (! $stillDue) {
                return 'deferred';
            }

            $this->transportClient->terminate($upload, forExpiry: true);

            return 'termination_requested';
        } catch (UploadTransportException) {
            return 'deferred';
        }
    }

    private function isDue(Upload $upload): bool
    {
        return in_array($upload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true)
            && $upload->expires_at !== null
            && $upload->expires_at->lessThanOrEqualTo(now());
    }
}
