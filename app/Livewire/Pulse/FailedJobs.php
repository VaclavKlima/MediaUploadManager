<?php

namespace App\Livewire\Pulse;

use App\Actions\RetryFailedJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use RuntimeException;

#[Lazy]
class FailedJobs extends Card
{
    public ?string $pendingRetryUuid = null;

    public ?string $retryMessage = null;

    public function requestRetry(string $uuid, RetryFailedJob $retryFailedJob): void
    {
        $this->authorizeAdministrator();

        if (! $retryFailedJob->isRetryable($uuid)) {
            throw new RuntimeException('This job is not available for manual retry.');
        }

        $this->pendingRetryUuid = $uuid;
        $this->retryMessage = null;
    }

    public function cancelRetry(): void
    {
        $this->pendingRetryUuid = null;
    }

    public function confirmRetry(RetryFailedJob $retryFailedJob): void
    {
        $user = $this->authorizeAdministrator();
        $uuid = $this->pendingRetryUuid;

        if ($uuid === null) {
            throw new RuntimeException('Choose a failed job before confirming retry.');
        }

        $retryFailedJob->execute($uuid, $user);
        $this->pendingRetryUuid = null;
        $this->retryMessage = 'The job was safely queued for retry.';
    }

    public function render(RetryFailedJob $retryFailedJob): View
    {
        $this->authorizeAdministrator();

        return view('livewire.pulse.failed-jobs', [
            'failedJobs' => $retryFailedJob->summaries(),
        ]);
    }

    private function authorizeAdministrator(): User
    {
        Gate::authorize('viewPulse');
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isAdministrator()) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
