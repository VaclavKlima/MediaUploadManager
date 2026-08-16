<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\EpisodeRenameOperation;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\Media\TusUploadReconciler;
use App\Support\Media\TusUploadTokenIssuer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class IssueUploadAuthorization
{
    public function __construct(
        private TusUploadReconciler $reconciler,
        private TusUploadTokenIssuer $tokenIssuer,
        private CacheManager $cacheManager,
    ) {}

    /**
     * @param  array{filename: string, declared_size: int, last_modified_milliseconds?: int|null, fingerprint_first_sha256: string, fingerprint_last_sha256: string}  $fingerprint
     * @return array{upload: Upload, token: string}
     */
    public function execute(Upload $upload, User $actor, array $fingerprint): array
    {
        $this->authorizeOwner($upload, $actor);

        if ($upload->series_episode_id === null) {
            return $this->issue($upload, $fingerprint);
        }

        $repository = $this->cacheManager->store('database');

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw new UploadTransportException(
                'series_authorization_unavailable',
                'Show upload authorization is temporarily unavailable.',
                503,
            );
        }

        try {
            $authorization = $repository->getStore()
                ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, 60)
                ->block(10, fn (): array => $this->issue($upload, $fingerprint));

            if (! is_array($authorization)
                || ! ($authorization['upload'] ?? null) instanceof Upload
                || ! is_string($authorization['token'] ?? null)
            ) {
                throw new LogicException('The upload authorization lock returned an invalid result.');
            }

            return [
                'upload' => $authorization['upload'],
                'token' => $authorization['token'],
            ];
        } catch (LockTimeoutException) {
            throw new UploadTransportException(
                'series_authorization_busy',
                'Show storage is busy. Please retry.',
                503,
            );
        }
    }

    /**
     * @param  array{filename: string, declared_size: int, last_modified_milliseconds?: int|null, fingerprint_first_sha256: string, fingerprint_last_sha256: string}  $fingerprint
     * @return array{upload: Upload, token: string}
     */
    private function issue(Upload $upload, array $fingerprint): array
    {
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

            $this->assertSeriesOperationsResolved($lockedUpload);

            if ($lockedUpload->series_upload_batch_id !== null) {
                $blockedByPredecessor = Upload::query()
                    ->where('series_upload_batch_id', $lockedUpload->series_upload_batch_id)
                    ->where('batch_position', '<', $lockedUpload->batch_position)
                    ->whereNotIn('status', [UploadStatus::Completed->value, UploadStatus::Cancelled->value])
                    ->exists();

                if ($blockedByPredecessor) {
                    throw new UploadTransportException(
                        'series_batch_out_of_sequence',
                        'Complete or cancel the preceding episode before starting this upload.',
                        409,
                    );
                }
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

    private function assertSeriesOperationsResolved(Upload $upload): void
    {
        if ($upload->series_episode_id === null) {
            return;
        }

        $episode = SeriesEpisode::query()->with('season.series')->findOrFail($upload->series_episode_id);
        $episodeIds = SeriesEpisode::query()
            ->whereIn('series_season_id', $episode->season->series->seasons()->select('id'))
            ->select('id');
        $isBlocked = SeriesDeletionOperation::query()
            ->where('series_id', $episode->season->series_id)
            ->whereNot('status', 'completed')
            ->exists()
            || EpisodeRenameOperation::query()
                ->whereIn('series_episode_id', $episodeIds)
                ->whereNot('status', 'completed')
                ->exists();

        if ($isBlocked) {
            throw new UploadTransportException(
                'series_operation_unresolved',
                'Resolve the Show rename or deletion operation before authorizing an upload.',
                409,
            );
        }
    }

    private function authorizeOwner(Upload $upload, User $actor): void
    {
        if ($upload->user_id !== $actor->getKey() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }
    }
}
