<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\Media\TusTransportClient;
use App\Support\Media\TusUploadReconciler;
use App\Support\Media\TusUploadTokenIssuer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class CancelUpload
{
    public function __construct(
        private TusUploadReconciler $reconciler,
        private TusTransportClient $transportClient,
        private TransitionUploadStatus $transitionUploadStatus,
        private TusUploadTokenIssuer $tokenIssuer,
    ) {}

    public function execute(Upload $upload, User $actor): Upload
    {
        if ($upload->user_id !== $actor->getKey() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }

        if ($upload->status === UploadStatus::Cancelled) {
            return $upload->refresh();
        }

        if ($upload->tus_resource_id !== null || $upload->tus_creation_claimed_at !== null) {
            $upload = $this->reconciler->reconcile($upload);
        }

        if ($upload->status === UploadStatus::Processing) {
            throw new UploadTransportException(
                'upload_not_cancellable',
                'An upload awaiting validation cannot be cancelled.',
            );
        }

        if (! in_array($upload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused, UploadStatus::Cancelled], true)) {
            throw new UploadTransportException(
                'upload_not_cancellable',
                'This upload session cannot be cancelled.',
            );
        }

        if ($upload->tus_resource_id !== null) {
            $this->transportClient->terminate($upload);
        }

        return DB::transaction(function () use ($upload, $actor): Upload {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUpload->status === UploadStatus::Processing) {
                throw new UploadTransportException(
                    'upload_not_cancellable',
                    'An upload awaiting validation cannot be cancelled.',
                );
            }

            if ($lockedUpload->status !== UploadStatus::Cancelled) {
                $lockedUpload = $this->transitionUploadStatus->asUser($lockedUpload, UploadStatus::Cancelled, $actor);
            }

            $this->tokenIssuer->revoke($lockedUpload);

            return $lockedUpload->refresh();
        }, attempts: 3);
    }
}
