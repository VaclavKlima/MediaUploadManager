<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLibraryScanRequest;
use App\Jobs\ScanMovieLibrary;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LibraryScanController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAdministrator($request);
        $scan = LibraryScan::query()->latest('id')->first();
        $findings = $scan === null
            ? (new LibraryFinding)->newCollection()
            : $scan->findings()
                ->with('mediaItem:id,title,release_year,poster_path')
                ->with('pairedMissingFinding:id,disk_id,relative_path,size_bytes,media_file_id')
                ->orderBy('id')
                ->get();
        $pairedMissingIds = $findings->pluck('paired_missing_finding_id')->filter()->all();
        $tasks = $findings
            ->reject(fn (LibraryFinding $finding): bool => in_array($finding->id, $pairedMissingIds, true))
            ->filter(fn (LibraryFinding $finding): bool => $this->taskType($finding) !== null)
            ->sortBy(function (LibraryFinding $finding): array {
                $taskType = $this->taskType($finding);

                return [$taskType === null ? 100 : $this->taskPriority($taskType), $finding->id];
            })
            ->map(fn (LibraryFinding $finding): array => $this->task($finding))
            ->values();
        $history = LibraryFinding::query()
            ->whereNotNull('resolved_at')
            ->whereNotIn('id', LibraryFinding::query()
                ->whereNotNull('paired_missing_finding_id')
                ->select('paired_missing_finding_id'))
            ->with('mediaItem:id,title')
            ->latest('resolved_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (LibraryFinding $finding): array => [
                'id' => $finding->id,
                'name' => $finding->identity_snapshot['title']
                    ?? $this->relatedMediaItemTitle($finding)
                    ?? $finding->source_filename,
                'outcome' => $finding->resolution,
                'completed_at' => $finding->resolved_at?->toIso8601String(),
            ])
            ->values();
        $maintenance = FolderCleanup::query()
            ->whereIn('id', FolderCleanup::query()->selectRaw('MAX(id)')->groupBy('library_finding_id'))
            ->whereIn('status', ['failed', 'partial'])
            ->latest('updated_at')
            ->get(['id', 'status', 'error_detail']);

        return Inertia::render('movies/Scan', [
            'scan' => $scan === null ? null : [
                'id' => $scan->id,
                'status' => $scan->status,
                'discovered_count' => $scan->discovered_count,
                'missing_count' => $scan->missing_count,
                'error_detail' => $scan->error_detail,
                'started_at' => $scan->started_at?->toIso8601String(),
                'completed_at' => $scan->completed_at?->toIso8601String(),
            ],
            'tasks' => $tasks,
            'remaining_count' => $tasks->count(),
            'processing_count' => $findings
                ->reject(fn (LibraryFinding $finding): bool => in_array($finding->id, $pairedMissingIds, true))
                ->whereIn('status', ['import_queued', 'importing', 'restore_queued', 'restoring', 'deleting'])
                ->count(),
            'progress' => [
                'completed' => $findings
                    ->reject(fn (LibraryFinding $finding): bool => in_array($finding->id, $pairedMissingIds, true))
                    ->whereNotNull('resolved_at')
                    ->count(),
                'total' => $findings->count() - count($pairedMissingIds),
            ],
            'history' => $history,
            'maintenance_warning' => $maintenance->isEmpty() ? null : [
                'count' => $maintenance->count(),
                'message' => $maintenance->first()->error_detail
                    ?? 'Background folder cleanup needs another scan to retry.',
            ],
            'unavailable' => collect($scan === null ? [] : $scan->disk_statuses)
                ->where('health', 'unhealthy')
                ->values(),
        ]);
    }

    public function store(StoreLibraryScanRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $active = LibraryScan::query()->whereIn('status', ['queued', 'scanning'])->latest('id')->first();

        if ($active === null) {
            $active = LibraryScan::query()->create(['user_id' => $user->id, 'status' => 'queued']);
            ScanMovieLibrary::dispatch($active->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Library scan queued.']);

        return to_route('library_scans.index');
    }

    /** @return array<string, mixed> */
    private function task(LibraryFinding $finding): array
    {
        $taskType = $this->taskType($finding);

        if ($taskType === null) {
            throw new \LogicException('Only actionable findings may be serialized as tasks.');
        }

        $mediaItem = $this->relatedMediaItem($finding);
        $posterPath = $finding->identity_snapshot['poster_path'] ?? $mediaItem?->poster_path;
        $pairedMissing = $finding->getRelation('pairedMissingFinding');

        return [
            'id' => $finding->id,
            'task_type' => $taskType,
            'disk_id' => $finding->disk_id,
            'relative_path' => $finding->relative_path,
            'source_filename' => $finding->source_filename,
            'size_bytes' => $finding->size_bytes,
            'status' => $finding->status,
            'tmdb_id' => $finding->tmdb_id,
            'imdb_id' => $finding->imdb_id,
            'title' => $finding->identity_snapshot['title'] ?? $mediaItem?->title,
            'release_year' => $finding->identity_snapshot['release_year'] ?? $mediaItem?->release_year,
            'poster_url' => is_string($posterPath)
                ? 'https://image.tmdb.org/t/p/w342'.$posterPath
                : null,
            'destination_relative_path' => $finding->destination_relative_path,
            'error_detail' => $finding->error_detail,
            'tracked_source' => $pairedMissing instanceof LibraryFinding ? [
                'finding_id' => $pairedMissing->id,
                'media_file_id' => $pairedMissing->media_file_id,
                'disk_id' => $pairedMissing->disk_id,
                'relative_path' => $pairedMissing->relative_path,
                'size_bytes' => $pairedMissing->size_bytes,
            ] : null,
        ];
    }

    private function taskType(LibraryFinding $finding): ?string
    {
        return match (true) {
            $finding->kind === 'discovered' && in_array($finding->status, ['needs_identification', 'conflict'], true) => 'identify',
            $finding->kind === 'discovered' && $finding->status === 'ready' => 'import',
            $finding->kind === 'discovered' && $finding->status === 'restore_ready' => 'restore',
            $finding->kind === 'discovered'
                && $finding->status === 'failed'
                && ($finding->operation_claim['type'] ?? null) === 'delete' => 'retry_delete',
            $finding->kind === 'discovered'
                && $finding->status === 'failed'
                && ($finding->operation_claim['type'] ?? null) === 'restore' => 'retry_restore',
            $finding->kind === 'discovered'
                && $finding->status === 'failed'
                && $finding->operation_claim === null
                && $finding->paired_missing_finding_id !== null => 'retry_restore',
            $finding->kind === 'discovered'
                && $finding->status === 'failed'
                && $this->canRetryImport($finding) => 'retry_import',
            $finding->kind === 'missing' && $finding->status === 'missing' => 'missing',
            default => null,
        };
    }

    private function taskPriority(string $taskType): int
    {
        return match ($taskType) {
            'identify', 'restore', 'retry_restore' => 10,
            'retry_import', 'retry_delete' => 20,
            'import' => 21,
            'missing' => 30,
            default => 100,
        };
    }

    private function canRetryImport(LibraryFinding $finding): bool
    {
        $claim = $finding->operation_claim;

        if ($claim === null) {
            return true;
        }

        $claimType = $claim['type'] ?? null;

        return $claimType === 'import'
            || ($claimType === null
                && is_string($claim['source_relative_path'] ?? null)
                && is_string($claim['destination_relative_path'] ?? null));
    }

    private function relatedMediaItem(LibraryFinding $finding): ?MediaItem
    {
        if ($finding->media_item_id === null) {
            return null;
        }

        $mediaItem = $finding->getRelation('mediaItem');

        return $mediaItem instanceof MediaItem ? $mediaItem : null;
    }

    private function relatedMediaItemTitle(LibraryFinding $finding): ?string
    {
        $mediaItem = $this->relatedMediaItem($finding);

        return $mediaItem === null ? null : $mediaItem->title;
    }

    private function authorizeAdministrator(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAdministrator(), 403);
    }
}
