<?php

namespace App\Http\Controllers;

use App\Enums\MediaRootKind;
use App\Http\Requests\StoreLibraryScanRequest;
use App\Jobs\ScanMediaLibrary;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaItem;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                ->with('seriesEpisode:id,series_season_id,episode_number,name,custom_name')
                ->with('seriesEpisode.season:id,series_id,season_number')
                ->with('seriesEpisode.season.series:id,name,first_air_year,poster_path,category')
                ->with('pairedMissingFinding:id,root_kind,disk_id,relative_path,size_bytes,media_file_id')
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
            ->with('seriesEpisode:id,series_season_id,episode_number,name,custom_name')
            ->with('seriesEpisode.season:id,series_id,season_number')
            ->with('seriesEpisode.season.series:id,name')
            ->latest('resolved_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (LibraryFinding $finding): array => [
                'id' => $finding->id,
                'media_type' => $finding->root_kind === MediaRootKind::Series ? 'show' : 'movie',
                'name' => $this->findingDisplayName($finding),
                'outcome' => $finding->resolution,
                'completed_at' => $finding->resolved_at?->toIso8601String(),
            ])
            ->values();
        $maintenance = FolderCleanup::query()
            ->whereIn('id', FolderCleanup::query()->selectRaw('MAX(id)')->groupBy('library_finding_id'))
            ->whereIn('status', ['failed', 'partial'])
            ->latest('updated_at')
            ->get(['id', 'status', 'error_detail']);

        return Inertia::render('library-scans/Index', [
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
                ->map(function (mixed $status): array {
                    if (! is_array($status)) {
                        throw new \LogicException('A library scan disk status must be an array.');
                    }

                    return [
                        ...$status,
                        'root_kind' => $status['root_kind'] ?? $status['kind'] ?? MediaRootKind::Movies->value,
                    ];
                })
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
            ScanMediaLibrary::dispatch($active->id);
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
        $seriesEpisode = $this->relatedSeriesEpisode($finding);
        $series = $seriesEpisode?->season->series;
        $cataloguedSeries = $series ?? ($finding->tmdb_id === null
            ? null
            : Series::query()->where('tmdb_id', $finding->tmdb_id)->first());
        $seriesSnapshot = $finding->identity_snapshot['series'] ?? null;
        $episodeSnapshot = $finding->identity_snapshot['episode'] ?? null;
        $isSeries = $finding->root_kind === MediaRootKind::Series;
        $posterPath = $isSeries
            ? (is_array($seriesSnapshot) ? ($seriesSnapshot['poster_path'] ?? null) : $cataloguedSeries?->poster_path)
            : ($finding->identity_snapshot['poster_path'] ?? $mediaItem?->poster_path);
        $pairedMissing = $finding->getRelation('pairedMissingFinding');

        return [
            'id' => $finding->id,
            'media_type' => $isSeries ? 'show' : 'movie',
            'root_kind' => $finding->root_kind->value,
            'task_type' => $taskType,
            'disk_id' => $finding->disk_id,
            'relative_path' => $finding->relative_path,
            'source_folder' => $finding->source_folder,
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
            'movie' => $isSeries ? null : [
                'tmdb_id' => $finding->tmdb_id,
                'imdb_id' => $finding->imdb_id,
                'title' => $finding->identity_snapshot['title'] ?? $mediaItem?->title,
                'release_year' => $finding->identity_snapshot['release_year'] ?? $mediaItem?->release_year,
            ],
            'show' => ! $isSeries ? null : [
                'tmdb_id' => $finding->tmdb_id,
                'name' => is_array($seriesSnapshot) ? ($seriesSnapshot['name'] ?? null) : $cataloguedSeries?->name,
                'first_air_year' => is_array($seriesSnapshot) ? ($seriesSnapshot['first_air_year'] ?? null) : $cataloguedSeries?->first_air_year,
                'category' => $finding->series_category === null
                    ? $cataloguedSeries?->category->value
                    : $finding->series_category->value,
                'category_required' => $cataloguedSeries === null,
                'season_number' => $finding->season_number,
                'episode_number' => $finding->episode_number,
                'episode_name' => is_array($episodeSnapshot)
                    ? ($episodeSnapshot['name'] ?? null)
                    : $seriesEpisode?->displayName(),
                'series_episode_id' => $finding->series_episode_id,
                'parse_error' => $finding->error_detail,
                'search_query' => $this->showSearchQuery($finding),
            ],
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

    private function relatedSeriesEpisode(LibraryFinding $finding): ?SeriesEpisode
    {
        if ($finding->series_episode_id === null) {
            return null;
        }

        $episode = $finding->getRelation('seriesEpisode');

        return $episode instanceof SeriesEpisode ? $episode : null;
    }

    private function showSearchQuery(LibraryFinding $finding): string
    {
        if ($finding->source_folder !== '') {
            return Str::before($finding->source_folder, '/');
        }

        return Str::of($finding->source_filename)->beforeLast('.')->toString();
    }

    private function findingDisplayName(LibraryFinding $finding): string
    {
        if ($finding->root_kind === MediaRootKind::Series) {
            $seriesEpisode = $this->relatedSeriesEpisode($finding);
            $snapshot = is_array($finding->identity_snapshot) ? $finding->identity_snapshot : [];
            $seriesSnapshot = is_array($snapshot['series'] ?? null) ? $snapshot['series'] : [];
            $episodeSnapshot = is_array($snapshot['episode'] ?? null) ? $snapshot['episode'] : [];
            $seriesName = $seriesSnapshot['name']
                ?? $seriesEpisode?->season->series->name;
            $episodeName = $episodeSnapshot['name']
                ?? $seriesEpisode?->displayName();

            if (is_string($seriesName)) {
                $identity = is_int($finding->season_number) && is_int($finding->episode_number)
                    ? sprintf('S%02dE%02d', $finding->season_number, $finding->episode_number)
                    : null;

                return collect([$seriesName, $identity, is_string($episodeName) ? $episodeName : null])
                    ->filter()
                    ->join(' · ');
            }
        }

        $snapshotTitle = $finding->identity_snapshot['title'] ?? null;

        return is_string($snapshotTitle)
            ? $snapshotTitle
            : ($this->relatedMediaItemTitle($finding) ?? $finding->source_filename);
    }

    private function authorizeAdministrator(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAdministrator(), 403);
    }
}
