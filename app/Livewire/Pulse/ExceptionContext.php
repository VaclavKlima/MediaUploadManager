<?php

namespace App\Livewire\Pulse;

use App\Support\Pulse\IncidentContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use RuntimeException;

#[Lazy]
class ExceptionContext extends Card
{
    public ?string $selectedFingerprint = null;

    public function select(string $fingerprint, IncidentContext $incidentContext): void
    {
        Gate::authorize('viewPulse');

        if (Arr::first(
            $this->incidents($incidentContext),
            fn (array $context): bool => $context['fingerprint'] === $fingerprint,
        ) === null) {
            throw new RuntimeException('This exception context is no longer available.');
        }

        $this->selectedFingerprint = $fingerprint;
    }

    public function closeDetails(): void
    {
        Gate::authorize('viewPulse');
        $this->selectedFingerprint = null;
    }

    public function render(IncidentContext $incidentContext): View
    {
        Gate::authorize('viewPulse');

        $incidents = $this->incidents($incidentContext);
        $selectedContext = $this->selectedFingerprint === null
            ? null
            : Arr::first(
                $incidents,
                fn (array $context): bool => $context['fingerprint'] === $this->selectedFingerprint,
            );
        $selectedExport = is_array($selectedContext)
            ? [
                'json' => $incidentContext->toJson($selectedContext),
                'markdown' => $incidentContext->toMarkdown($selectedContext),
            ]
            : null;

        return view('livewire.pulse.exception-context', compact(
            'incidents',
            'selectedContext',
            'selectedExport',
        ));
    }

    /** @return array<int, non-empty-array<string, mixed>> */
    private function incidents(IncidentContext $incidentContext): array
    {
        $cutoff = CarbonImmutable::now()->sub($this->periodAsInterval())->getTimestamp();

        return $this->values(IncidentContext::PULSE_TYPE)
            ->filter(fn (object $value): bool => $value->timestamp >= $cutoff)
            ->map(fn (object $value): ?array => $incidentContext->decode($value->value))
            ->filter(fn (?array $context): bool => $context !== null && $context['source'] === 'exception')
            ->sortByDesc('occurred_at')
            ->take(25)
            ->values()
            ->all();
    }
}
