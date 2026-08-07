<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\MediaFile;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\Media\UploadProcessingFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RetryFailedUpload
{
    public function __construct(private TransitionUploadStatus $transitionUploadStatus) {}

    public function execute(Upload $upload, User $actor): Upload
    {
        if ($upload->user_id !== $actor->getKey() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }

        $retriedUpload = DB::transaction(function () use ($upload): Upload {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUpload->status !== UploadStatus::Failed
                || ! UploadProcessingFailure::isRecoverable($lockedUpload->error_code)
                || MediaFile::query()->where('source_upload_id', $lockedUpload->getKey())->exists()
            ) {
                throw new UploadTransportException(
                    'upload_retry_forbidden',
                    'This failed upload cannot be retried safely.',
                );
            }

            return $this->transitionUploadStatus->retryAsSystem($lockedUpload);
        }, attempts: 3);

        ProcessCompletedUpload::dispatch($retriedUpload->id);

        return $retriedUpload->refresh();
    }
}
