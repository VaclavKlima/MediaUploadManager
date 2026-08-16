<?php

namespace App\Actions;

use App\Enums\SeriesBatchStatus;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransitionUploadStatus
{
    public function asUser(Upload $upload, UploadStatus $target, User $actor): Upload
    {
        if ($actor->getKey() !== $upload->user_id && ! $actor->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }

        if (! in_array($target, [UploadStatus::Paused, UploadStatus::Cancelled], true)) {
            throw new AuthorizationException('This upload transition is system-only.');
        }

        if ($upload->status === UploadStatus::Expired
            && ($target !== UploadStatus::Cancelled || $upload->series_upload_batch_id === null)
        ) {
            throw new AuthorizationException('Only an expired Series batch item may be explicitly skipped.');
        }

        return $this->compareAndSet($upload, $target);
    }

    public function asSystem(Upload $upload, UploadStatus $target): Upload
    {
        if ($upload->status === UploadStatus::Expired && $target === UploadStatus::Cancelled) {
            throw new DomainException('An expired upload may be cancelled only by an explicit user acknowledgement.');
        }

        if ($target === UploadStatus::Failed) {
            throw new DomainException('System failures must include a safe error through failAsSystem.');
        }

        if ($upload->status === UploadStatus::Failed && $target === UploadStatus::Processing) {
            throw new DomainException('Failed uploads must use the dedicated system retry transition.');
        }

        if ($upload->status === UploadStatus::Failed && $target === UploadStatus::Cancelled) {
            throw new DomainException('Failed uploads must use the dedicated discard transition.');
        }

        return $this->compareAndSet($upload, $target);
    }

    public function failAsSystem(Upload $upload, string $code, ?string $detail = null): Upload
    {
        return $this->compareAndSet($upload, UploadStatus::Failed, [
            'error_code' => $this->normalizeFailureCode($code),
            'error_detail' => $this->sanitizeFailureDetail($detail),
            'failed_at' => $upload->failed_at ?? now(),
        ]);
    }

    public function retryAsSystem(Upload $upload): Upload
    {
        if ($upload->status !== UploadStatus::Failed && $upload->status !== UploadStatus::Processing) {
            throw new DomainException('Only a failed upload may be retried.');
        }

        return $this->compareAndSet($upload, UploadStatus::Processing, [
            'error_code' => null,
            'error_detail' => null,
            'processing_at' => now(),
        ]);
    }

    public function discardAsSystem(Upload $upload): Upload
    {
        if ($upload->status !== UploadStatus::Failed) {
            throw new DomainException('Only a failed upload may be discarded.');
        }

        return $this->compareAndSet($upload, UploadStatus::Cancelled);
    }

    /**
     * @param  array<string, mixed>  $additionalUpdates
     */
    private function compareAndSet(Upload $upload, UploadStatus $target, array $additionalUpdates = []): Upload
    {
        $source = $upload->status;

        if ($source === $target) {
            return $upload->refresh();
        }

        if (! $source->mayTransitionTo($target)) {
            throw new DomainException("Upload status cannot transition from {$source->value} to {$target->value}.");
        }

        $updates = [
            'status' => $target->value,
            'last_activity_at' => now(),
            'updated_at' => now(),
            ...$this->lifecycleUpdates($upload, $target),
            ...$additionalUpdates,
        ];

        $updatedRows = Upload::query()
            ->whereKey($upload->getKey())
            ->where('status', $source->value)
            ->update($updates);

        $upload->refresh();

        $this->synchronizeSeriesBatch($upload);

        if ($updatedRows === 1 || $upload->status === $target) {
            return $upload;
        }

        throw new DomainException('The upload changed before the requested transition could be applied.');
    }

    private function synchronizeSeriesBatch(Upload $upload): void
    {
        if ($upload->series_upload_batch_id === null) {
            return;
        }

        DB::transaction(function () use ($upload): void {
            $batch = $upload->seriesUploadBatch()->lockForUpdate()->first();

            if ($batch === null) {
                return;
            }

            $uploads = $batch->uploads()->lockForUpdate()->get(['status', 'confirmed_offset']);
            $confirmedBytes = 0;

            foreach ($uploads as $item) {
                $confirmedBytes += $item->confirmed_offset;
            }

            $batch->confirmed_bytes = max($batch->confirmed_bytes, $confirmedBytes);

            if ($uploads->every(fn (Upload $item): bool => in_array($item->status, [UploadStatus::Completed, UploadStatus::Cancelled], true))) {
                $batch->status = $uploads->contains(fn (Upload $item): bool => $item->status === UploadStatus::Cancelled)
                    ? SeriesBatchStatus::Cancelled
                    : SeriesBatchStatus::Completed;
                $batch->completed_at = $batch->status === SeriesBatchStatus::Completed ? ($batch->completed_at ?? now()) : null;
                $batch->cancelled_at = $batch->status === SeriesBatchStatus::Cancelled ? ($batch->cancelled_at ?? now()) : null;
            } elseif ($uploads->contains(fn (Upload $item): bool => in_array($item->status, [UploadStatus::Failed, UploadStatus::Paused, UploadStatus::Expired], true))) {
                $batch->status = SeriesBatchStatus::Paused;
                $batch->paused_at ??= now();
            } elseif ($uploads->contains(fn (Upload $item): bool => $item->status !== UploadStatus::Pending)) {
                $batch->status = SeriesBatchStatus::Uploading;
                $batch->started_at ??= now();
            }

            $batch->save();
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycleUpdates(Upload $upload, UploadStatus $target): array
    {
        return match ($target) {
            UploadStatus::Uploading => ['uploading_at' => $upload->uploading_at ?? now()],
            UploadStatus::Paused => ['paused_at' => now()],
            UploadStatus::Processing => ['processing_at' => now()],
            UploadStatus::Completed => ['completed_at' => now()],
            UploadStatus::Failed => ['failed_at' => $upload->failed_at ?? now()],
            UploadStatus::Cancelled => ['cancelled_at' => now()],
            UploadStatus::Expired => ['expired_at' => now()],
            UploadStatus::Pending => [],
        };
    }

    private function normalizeFailureCode(string $code): string
    {
        $normalizedCode = Str::of($code)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if (Str::length($normalizedCode) > 64 || preg_match('/\A[a-z][a-z0-9_]*\z/', $normalizedCode) !== 1) {
            throw new DomainException('An upload failure code must use lowercase letters, numbers, and underscores.');
        }

        return $normalizedCode;
    }

    private function sanitizeFailureDetail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $safeDetail = Str::of($detail)
            ->replaceMatches('/[\x00-\x1F\x7F]/u', ' ')
            ->squish()
            ->limit(500, '')
            ->toString();

        return $safeDetail === '' ? null : $safeDetail;
    }
}
