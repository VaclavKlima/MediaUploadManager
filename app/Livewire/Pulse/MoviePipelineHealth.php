<?php

namespace App\Livewire\Pulse;

use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\Upload;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class MoviePipelineHealth extends Card
{
    public function render(): View
    {
        Gate::authorize('viewPulse');

        $processingUploads = Upload::query()->where('status', UploadStatus::Processing)->count();
        $failedUploads = Upload::query()->where('status', UploadStatus::Failed)->count();
        $activeScans = LibraryScan::query()->whereIn('status', ['pending', 'scanning'])->count();
        $failedScans = LibraryScan::query()->where('status', 'failed')->count();
        $failedFindings = LibraryFinding::query()->where('status', 'failed')->count();

        return view('livewire.pulse.movie-pipeline-health', [
            'metrics' => [
                ['name' => 'Processing uploads', 'value' => $processingUploads, 'warning' => false],
                ['name' => 'Failed uploads', 'value' => $failedUploads, 'warning' => $failedUploads > 0],
                ['name' => 'Active scans', 'value' => $activeScans, 'warning' => false],
                ['name' => 'Failed scans', 'value' => $failedScans, 'warning' => $failedScans > 0],
                ['name' => 'Failed findings', 'value' => $failedFindings, 'warning' => $failedFindings > 0],
            ],
        ]);
    }
}
