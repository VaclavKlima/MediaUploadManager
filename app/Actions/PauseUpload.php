<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\Media\TusUploadReconciler;
use App\Support\Media\TusUploadTokenIssuer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class PauseUpload
{
    public function __construct(
        private TransitionUploadStatus $transitionUploadStatus,
        private TusUploadTokenIssuer $tokenIssuer,
        private TusUploadReconciler $reconciler,
    ) {}

    public function execute(Upload $upload, User $actor): Upload
    {
        if ($upload->user_id !== $actor->getKey() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }

        if ($upload->tus_resource_id !== null || $upload->tus_creation_claimed_at !== null) {
            $upload = $this->reconciler->reconcile($upload);
        }

        return DB::transaction(function () use ($upload, $actor): Upload {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($lockedUpload->status, [UploadStatus::Uploading, UploadStatus::Paused], true)) {
                throw new UploadTransportException(
                    'upload_not_pausable',
                    'Only an active upload may be paused.',
                );
            }

            if ($lockedUpload->status === UploadStatus::Uploading) {
                $lockedUpload = $this->transitionUploadStatus->asUser($lockedUpload, UploadStatus::Paused, $actor);
            }

            $this->tokenIssuer->revoke($lockedUpload);

            return $lockedUpload->refresh();
        }, attempts: 3);
    }
}
