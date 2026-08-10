<?php

namespace App\Livewire\Pulse;

use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\MediaDiskHealthChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Throwable;

#[Lazy]
class MediaDiskHealth extends Card
{
    public function render(
        ConfiguredDiskRegistry $diskRegistry,
        MediaDiskHealthChecker $healthChecker,
    ): View {
        Gate::authorize('viewPulse');

        try {
            $disks = collect($diskRegistry->all())
                ->map(fn ($disk): array => $healthChecker
                    ->check($disk, $diskRegistry->requiresMountpoint())
                    ->toArray())
                ->all();
            $configurationError = null;
        } catch (Throwable) {
            $disks = [];
            $configurationError = 'Media-disk health is unavailable because the configuration could not be loaded.';
        }

        return view('livewire.pulse.media-disk-health', compact('disks', 'configurationError'));
    }
}
