<?php

namespace App\Livewire\Pulse;

use App\Support\Operations\ProcessHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Throwable;

#[Lazy]
class ProcessHealth extends Card
{
    public function render(): View
    {
        Gate::authorize('viewPulse');

        return view('livewire.pulse.process-health', [
            'processes' => [
                $this->status('Scheduler', Cache::get(ProcessHeartbeat::SCHEDULER_KEY), 120),
                $this->status('Queue worker', Cache::get(ProcessHeartbeat::QUEUE_WORKER_KEY), 90),
                $this->status('Pulse check', $this->latestPulseTimestamp(), 90),
            ],
        ]);
    }

    /** @return array{name: string, healthy: bool, last_seen: string|null} */
    private function status(string $name, mixed $timestamp, int $maximumAgeSeconds): array
    {
        $seenAt = is_int($timestamp) ? $timestamp : null;

        return [
            'name' => $name,
            'healthy' => $seenAt !== null && $seenAt >= now()->subSeconds($maximumAgeSeconds)->getTimestamp(),
            'last_seen' => $seenAt === null ? null : CarbonImmutable::createFromTimestamp($seenAt)->diffForHumans(),
        ];
    }

    private function latestPulseTimestamp(): ?int
    {
        try {
            $configuredConnection = config('pulse.storage.database.connection');
            $connection = is_string($configuredConnection) ? $configuredConnection : null;

            $timestamp = DB::connection($connection)
                ->table('pulse_values')
                ->where('type', 'system')
                ->max('timestamp');

            return is_int($timestamp) ? $timestamp : (is_numeric($timestamp) ? (int) $timestamp : null);
        } catch (Throwable) {
            return null;
        }
    }
}
