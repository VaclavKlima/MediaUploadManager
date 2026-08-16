<?php

namespace App\Actions;

use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\SecurityAudit;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ReconcileMissingMediaFile
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private CacheManager $cacheManager,
    ) {}

    public function execute(LibraryFinding $finding, User $actor, bool $confirmed): void
    {
        if (! $actor->isAdministrator() || ! $confirmed) {
            throw new RuntimeException('Confirm external removal as an administrator.');
        }

        $repository = $this->cacheManager->store('database');

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw new RuntimeException('Missing-file reconciliation locking is unavailable.');
        }

        $repository->getStore()
            ->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, 240)
            ->block(10, fn () => $this->reconcile($finding->refresh(), $actor));
    }

    private function reconcile(LibraryFinding $finding, User $actor): void
    {
        $pairedDiscovered = LibraryFinding::query()
            ->where('paired_missing_finding_id', $finding->id)
            ->whereNull('resolved_at')
            ->first();

        $disk = $this->diskRegistry->findRoot($finding->disk_id, $finding->root_kind);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw new RuntimeException('The disk must be healthy before absence can be confirmed.');
        }

        $path = $this->pathGuard->resolveChild($disk->root, $finding->relative_path);

        if ($this->filesystem->pathExists($path)) {
            DB::transaction(function () use ($finding, $pairedDiscovered): void {
                $lockedFinding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();
                $lockedFinding->update([
                    'status' => 'resolved',
                    'resolution' => 'restored',
                    'resolved_at' => now(),
                    'error_detail' => null,
                ]);

                if ($pairedDiscovered instanceof LibraryFinding && $pairedDiscovered->operation_claim === null) {
                    LibraryFinding::query()->whereKey($pairedDiscovered)->lockForUpdate()->firstOrFail()->update([
                        'paired_missing_finding_id' => null,
                        'status' => 'conflict',
                        'error_detail' => 'The tracked path returned; this discovered file is now a normal conflict.',
                    ]);
                }
            }, attempts: 3);

            throw new RuntimeException('The file has returned and is no longer missing.');
        }

        if ($pairedDiscovered instanceof LibraryFinding) {
            throw new RuntimeException('Restore or dismiss the proven moved-file task before confirming removal.');
        }

        DB::transaction(function () use ($finding, $actor): void {
            $finding = LibraryFinding::query()->whereKey($finding)->lockForUpdate()->firstOrFail();

            if ($finding->resolution === 'external_missing') {
                return;
            }

            if ($finding->kind !== 'missing'
                || $finding->media_file_id === null
                || (($finding->media_item_id === null) === ($finding->series_episode_id === null))
            ) {
                throw new RuntimeException('This finding is not a tracked missing primary.');
            }

            $mediaFile = MediaFile::query()->whereKey($finding->media_file_id)->lockForUpdate()->firstOrFail();
            $subject = $finding->media_item_id !== null
                ? MediaItem::query()->whereKey($finding->media_item_id)->lockForUpdate()->firstOrFail()
                : SeriesEpisode::query()->whereKey($finding->series_episode_id)->lockForUpdate()->firstOrFail();

            if ($subject->current_media_file_id !== $mediaFile->id
                || $mediaFile->media_item_id !== $finding->media_item_id
                || $mediaFile->series_episode_id !== $finding->series_episode_id
                || $mediaFile->root_kind !== $finding->root_kind
                || $mediaFile->active_path_key !== MediaFile::activePathKey(
                    $mediaFile->disk_id,
                    $mediaFile->relative_path,
                    $mediaFile->root_kind,
                )
                || $mediaFile->removed_at !== null
            ) {
                throw new RuntimeException('The tracked primary changed before reconciliation.');
            }

            $mediaFile->update(['removed_at' => now(), 'removal_reason' => 'external_missing']);
            $subject->update(['current_media_file_id' => null]);

            if ($subject instanceof SeriesEpisode) {
                $series = Series::query()
                    ->whereIn('id', $subject->season()->select('series_id'))
                    ->lockForUpdate()
                    ->firstOrFail();
                $latest = MediaFile::query()
                    ->whereIn('series_episode_id', $series->episodes()->select('series_episodes.id'))
                    ->whereNotNull('active_path_key')
                    ->max('finalized_at');
                $series->update(['last_episode_finalized_at' => $latest]);
            }
            $finding->update([
                'status' => 'resolved',
                'resolution' => 'external_missing',
                'resolved_at' => now(),
                'error_detail' => null,
            ]);
            SecurityAudit::externalMediaRemovalConfirmed($finding, $mediaFile, $actor);
        }, attempts: 3);
    }
}
