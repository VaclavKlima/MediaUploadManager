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

final readonly class IssueUploadAuthorization
{
    public function __construct(
        private TusUploadReconciler $reconciler,
        private TusUploadTokenIssuer $tokenIssuer,
    ) {}

    /**
     * @param  array{filename: string, declared_size: int, last_modified_milliseconds?: int|null, fingerprint_first_sha256: string, fingerprint_last_sha256: string}  $fingerprint
     * @return array{upload: Upload, token: string}
     */
    public function execute(Upload $upload, User $actor, array $fingerprint): array
    {
        $this->authorizeOwner($upload, $actor);
        $upload = $this->reconciler->reconcile($upload);

        return DB::transaction(function () use ($upload, $fingerprint): array {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($lockedUpload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true)
                || $lockedUpload->expires_at === null
                || $lockedUpload->expires_at->lessThanOrEqualTo(now())
            ) {
                throw new UploadTransportException(
                    'upload_not_resumable',
                    'This upload session can no longer be resumed.',
                );
            }

            $matches = $lockedUpload->original_filename === $fingerprint['filename']
                && $lockedUpload->declared_size === $fingerprint['declared_size']
                && $lockedUpload->last_modified_milliseconds === ($fingerprint['last_modified_milliseconds'] ?? null)
                && hash_equals($lockedUpload->fingerprint_first_sha256, $fingerprint['fingerprint_first_sha256'])
                && hash_equals($lockedUpload->fingerprint_last_sha256, $fingerprint['fingerprint_last_sha256']);

            if (! $matches) {
                throw new UploadTransportException(
                    'upload_fingerprint_mismatch',
                    'The selected file does not exactly match this upload session.',
                    422,
                );
            }

            $token = $this->tokenIssuer->rotate($lockedUpload);

            return ['upload' => $lockedUpload->refresh(), 'token' => $token];
        }, attempts: 3);
    }

    private function authorizeOwner(Upload $upload, User $actor): void
    {
        if ($upload->user_id !== $actor->getKey() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }
    }
}
