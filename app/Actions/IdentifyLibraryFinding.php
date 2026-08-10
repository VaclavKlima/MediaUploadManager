<?php

namespace App\Actions;

use App\Models\LibraryFinding;
use App\Models\User;
use App\Support\Media\LibraryFindingIdentityDecision;
use App\Support\Media\LibraryFindingIdentityResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class IdentifyLibraryFinding
{
    public function __construct(
        private LibraryFindingIdentityResolver $resolver,
        private QueueLibraryFindingImport $queueImport,
        private QueueLibraryFindingRestore $queueRestore,
    ) {}

    public function preview(LibraryFinding $finding, int $tmdbId): LibraryFindingIdentityDecision
    {
        return $this->resolver->resolve($finding, $tmdbId);
    }

    public function execute(LibraryFinding $finding, int $tmdbId): LibraryFinding
    {
        $decision = $this->resolver->resolve($finding, $tmdbId);
        $this->persist($finding, $decision);

        return $finding->refresh();
    }

    public function identifyAndQueueImport(
        LibraryFinding $finding,
        int $tmdbId,
        string $expectedDestination,
        User $actor,
    ): LibraryFindingIdentityDecision {
        $decision = $this->resolver->resolve($finding, $tmdbId);

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

    private function persist(LibraryFinding $finding, LibraryFindingIdentityDecision $decision): void
    {
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
                LibraryFinding::query()
                    ->whereIn('id', $decision->duplicateFindingIds)
                    ->whereNull('resolved_at')
                    ->update([
                        'status' => 'conflict',
                        'error_detail' => 'Multiple discovered files identify the same movie; multiple versions are not supported.',
                    ]);
            }

            $locked->update([
                'media_item_id' => $decision->existingMediaItemId,
                'media_file_id' => $decision->relocation['media_file_id'] ?? null,
                'paired_missing_finding_id' => $decision->relocation['finding_id'] ?? null,
                'status' => $decision->canImport()
                    ? ($decision->operation === 'restore' ? 'restore_ready' : 'ready')
                    : 'conflict',
                'identity_source' => 'manual',
                'identity_snapshot' => $decision->snapshot,
                'tmdb_id' => $decision->tmdbId,
                'imdb_id' => $decision->imdbId,
                'destination_relative_path' => $decision->destinationRelativePath,
                'error_detail' => $decision->blockerMessage,
            ]);
        }, attempts: 3);
    }
}
