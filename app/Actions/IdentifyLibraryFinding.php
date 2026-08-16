<?php

namespace App\Actions;

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Models\LibraryFinding;
use App\Models\User;
use App\Support\Media\LibraryFindingIdentityDecision;
use App\Support\Media\LibraryFindingIdentityResolver;
use App\Support\Media\SeriesLibraryFindingIdentityDecision;
use App\Support\Media\SeriesLibraryFindingIdentityResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class IdentifyLibraryFinding
{
    public function __construct(
        private LibraryFindingIdentityResolver $resolver,
        private SeriesLibraryFindingIdentityResolver $seriesResolver,
        private QueueLibraryFindingImport $queueImport,
        private QueueLibraryFindingRestore $queueRestore,
    ) {}

    public function preview(
        LibraryFinding $finding,
        int $tmdbId,
        ?SeriesCategory $category = null,
        ?int $seasonNumber = null,
        ?int $episodeNumber = null,
    ): LibraryFindingIdentityDecision|SeriesLibraryFindingIdentityDecision {
        return $finding->root_kind === MediaRootKind::Series
            ? $this->seriesResolver->resolve(
                $finding,
                $tmdbId,
                $category,
                $seasonNumber ?? throw new RuntimeException('Choose a Show season.'),
                $episodeNumber ?? throw new RuntimeException('Choose a Show episode.'),
            )
            : $this->resolver->resolve($finding, $tmdbId);
    }

    public function execute(
        LibraryFinding $finding,
        int $tmdbId,
        ?SeriesCategory $category = null,
        ?int $seasonNumber = null,
        ?int $episodeNumber = null,
    ): LibraryFinding {
        $decision = $this->preview($finding, $tmdbId, $category, $seasonNumber, $episodeNumber);
        $this->persist($finding, $decision);

        return $finding->refresh();
    }

    public function identifyAndQueueImport(
        LibraryFinding $finding,
        int $tmdbId,
        string $expectedDestination,
        User $actor,
        ?SeriesCategory $category = null,
        ?int $seasonNumber = null,
        ?int $episodeNumber = null,
    ): LibraryFindingIdentityDecision|SeriesLibraryFindingIdentityDecision {
        $decision = $this->preview($finding, $tmdbId, $category, $seasonNumber, $episodeNumber);

        if (! hash_equals($decision->destinationRelativePath, $expectedDestination)) {
            throw new RuntimeException('The canonical destination changed after preview.');
        }

        $this->persist($finding, $decision);

        if ($decision->canImport()) {
            if ($decision->operation === 'restore') {
                $this->queueRestore->execute($finding->refresh(), $actor);
            } else {
                $this->queueImport->execute($finding->refresh(), $actor);
            }
        }

        return $decision;
    }

    private function persist(
        LibraryFinding $finding,
        LibraryFindingIdentityDecision|SeriesLibraryFindingIdentityDecision $decision,
    ): void {
        DB::transaction(function () use ($finding, $decision): void {
            $locked = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($locked->kind !== 'discovered'
                || $locked->resolved_at !== null
                || $locked->operation_claim !== null
                || ! in_array($locked->status, ['needs_identification', 'conflict', 'ready', 'failed'], true)
            ) {
                throw new RuntimeException('This finding can no longer be identified.');
            }

            if ($decision->duplicateFindingIds !== []) {
                $duplicateMessage = $decision instanceof SeriesLibraryFindingIdentityDecision
                    ? 'Multiple discovered files identify the same Show episode; multiple versions are not supported.'
                    : 'Multiple discovered files identify the same movie; multiple versions are not supported.';
                LibraryFinding::query()
                    ->whereIn('id', $decision->duplicateFindingIds)
                    ->whereNull('resolved_at')
                    ->update([
                        'status' => 'conflict',
                        'error_detail' => $duplicateMessage,
                    ]);
            }

            $locked->update([
                'media_item_id' => $decision instanceof LibraryFindingIdentityDecision
                    ? $decision->existingMediaItemId
                    : null,
                'series_episode_id' => $decision instanceof SeriesLibraryFindingIdentityDecision
                    ? $decision->existingEpisodeId
                    : null,
                'media_file_id' => $decision->relocation['media_file_id'] ?? null,
                'paired_missing_finding_id' => $decision->relocation['finding_id'] ?? null,
                'status' => $decision->canImport()
                    ? ($decision->operation === 'restore' ? 'restore_ready' : 'ready')
                    : 'conflict',
                'identity_source' => 'manual',
                'identity_snapshot' => $decision->snapshot,
                'tmdb_id' => $decision->tmdbId,
                'imdb_id' => $decision instanceof LibraryFindingIdentityDecision ? $decision->imdbId : null,
                'series_category' => $decision instanceof SeriesLibraryFindingIdentityDecision
                    ? $decision->category
                    : null,
                'season_number' => $decision instanceof SeriesLibraryFindingIdentityDecision
                    ? $decision->seasonNumber
                    : null,
                'episode_number' => $decision instanceof SeriesLibraryFindingIdentityDecision
                    ? $decision->episodeNumber
                    : null,
                'destination_relative_path' => $decision->destinationRelativePath,
                'error_detail' => $decision->blockerMessage,
            ]);
        }, attempts: 3);
    }
}
