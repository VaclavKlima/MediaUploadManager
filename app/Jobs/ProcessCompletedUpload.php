<?php

namespace App\Jobs;

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Support\Media\Exceptions\UploadProcessingException;
use App\Support\Media\FinalizeProcessedUpload;
use App\Support\Media\UploadConfiguration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessCompletedUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    private array $retryBackoff;

    public function __construct(public int $uploadId)
    {
        $configuration = app(UploadConfiguration::class);
        $this->timeout = $configuration->processingJobTimeoutSeconds;
        $this->retryBackoff = $configuration->processingJobBackoffSeconds;
        $this->tries = count($this->retryBackoff) + 1;
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(
        FinalizeProcessedUpload $finalizer,
        TransitionUploadStatus $transitionUploadStatus,
    ): void {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload === null) {
            return;
        }

        try {
            $finalizer->process($upload);
        } catch (UploadProcessingException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $this->markFailed($upload, $transitionUploadStatus, $exception->errorCode, $exception->safeDetail);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->uploadId;
    }

    public function uniqueFor(): int
    {
        return app(UploadConfiguration::class)->processingJobUniqueSeconds;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->retryBackoff;
    }

    public function failed(?Throwable $exception): void
    {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload === null) {
            return;
        }

        if ($exception instanceof UploadProcessingException) {
            $this->markFailed(
                $upload,
                app(TransitionUploadStatus::class),
                $exception->errorCode,
                $exception->safeDetail,
            );

            return;
        }

        $this->markFailed(
            $upload,
            app(TransitionUploadStatus::class),
            'media_processing_interrupted',
            'Media processing stopped after bounded retries and may be retried.',
        );
    }

    private function markFailed(
        Upload $upload,
        TransitionUploadStatus $transitionUploadStatus,
        string $errorCode,
        string $safeDetail,
    ): void {
        DB::transaction(function () use ($upload, $transitionUploadStatus, $errorCode, $safeDetail): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->first();

            if ($lockedUpload?->status === UploadStatus::Processing) {
                $transitionUploadStatus->failAsSystem($lockedUpload, $errorCode, $safeDetail);
            }
        }, attempts: 3);
    }
}
