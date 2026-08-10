<?php

namespace App\Actions;

use App\Jobs\CleanupResolvedLibraryFindingFolder;
use App\Jobs\DeleteDiscoveredLibraryFile;
use App\Jobs\ImportLibraryFinding;
use App\Jobs\ProcessCompletedUpload;
use App\Jobs\ProcessFolderCleanup;
use App\Jobs\ScanMovieLibrary;
use App\Models\User;
use App\Support\SecurityAudit;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class RetryFailedJob
{
    /**
     * @var array<class-string, string>
     */
    private const ALLOWED_JOBS = [
        ProcessCompletedUpload::class => 'Process completed upload',
        ScanMovieLibrary::class => 'Scan movie library',
        CleanupResolvedLibraryFindingFolder::class => 'Clean resolved library folder',
        DeleteDiscoveredLibraryFile::class => 'Delete discovered library file',
        ImportLibraryFinding::class => 'Import library finding',
        ProcessFolderCleanup::class => 'Process folder cleanup',
    ];

    public function __construct(private Application $application) {}

    /**
     * @return list<array{id: string, name: string, summary: string, failed_at: string|null, retryable: bool}>
     */
    public function summaries(): array
    {
        $summaries = [];

        foreach (array_slice($this->provider()->all(), 0, 25) as $failedJob) {
            if (! is_object($failedJob) || ! is_string($failedJob->id ?? null)) {
                continue;
            }

            $jobClass = $this->jobClass($failedJob);
            $failedAt = $failedJob->failed_at ?? null;
            $jobName = $jobClass === null ? null : (self::ALLOWED_JOBS[$jobClass] ?? null);
            $retryable = $jobName !== null;

            $summaries[] = [
                'id' => $failedJob->id,
                'name' => $jobName ?? 'Unsupported background job',
                'summary' => $retryable
                    ? 'A retryable movie-management task failed.'
                    : 'Manual retry is disabled for this task type.',
                'failed_at' => $failedAt instanceof CarbonInterface
                    ? $failedAt->toIso8601String()
                    : (is_string($failedAt) ? $failedAt : null),
                'retryable' => $retryable,
            ];
        }

        return $summaries;
    }

    public function isRetryable(string $uuid): bool
    {
        $failedJob = $this->provider()->find($uuid);

        return is_object($failedJob) && $this->isAllowedJob($this->jobClass($failedJob));
    }

    public function execute(string $uuid, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new AuthorizationException;
        }

        SecurityAudit::failedJobRetryRequested($uuid, $actor);

        $rateLimitKey = 'operations:failed-job-retry:'.$actor->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            SecurityAudit::failedJobRetryCompleted($uuid, $actor, false, 'rate_limited');

            throw new RuntimeException('Too many retry requests. Try again in a minute.');
        }

        RateLimiter::hit($rateLimitKey, 60);
        $lock = Cache::lock('operations:failed-job-retry:'.$uuid, 30);

        if (! $lock->get()) {
            SecurityAudit::failedJobRetryCompleted($uuid, $actor, false, 'locked');

            throw new RuntimeException('This failed job is already being retried.');
        }

        try {
            $failedJob = $this->provider()->find($uuid);

            if (! is_object($failedJob)) {
                throw new RuntimeException('The failed job is no longer available.');
            }

            if (! $this->isAllowedJob($this->jobClass($failedJob))) {
                throw new RuntimeException('This job type is not approved for manual retry.');
            }

            $exitCode = Artisan::call('queue:retry', ['id' => [$uuid]]);

            if ($exitCode !== 0 || $this->provider()->find($uuid) !== null) {
                throw new RuntimeException('The failed job could not be queued for retry.');
            }

            SecurityAudit::failedJobRetryCompleted($uuid, $actor, true, 'queued');
        } catch (Throwable $exception) {
            SecurityAudit::failedJobRetryCompleted($uuid, $actor, false, 'rejected');

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function provider(): FailedJobProviderInterface
    {
        return $this->application->make('queue.failer');
    }

    private function jobClass(object $failedJob): ?string
    {
        if (! is_string($failedJob->payload ?? null)) {
            return null;
        }

        $payload = json_decode($failedJob->payload, true);
        $jobClass = is_array($payload) ? data_get($payload, 'data.commandName') : null;

        return is_string($jobClass) && Str::startsWith($jobClass, 'App\\Jobs\\')
            ? $jobClass
            : null;
    }

    private function isAllowedJob(?string $jobClass): bool
    {
        return $jobClass !== null && isset(self::ALLOWED_JOBS[$jobClass]);
    }
}
