<?php

namespace App\Actions\Series;

use App\Actions\CreateOrReplayUploadReservation;
use App\Enums\MediaRootKind;
use App\Enums\SeriesBatchStatus;
use App\Enums\UploadStatus;
use App\Models\EpisodeRenameOperation;
use App\Models\Series;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\SeriesUploadBatch;
use App\Models\Upload;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\UploadConfiguration;
use App\Support\Series\JellyfinSeriesPathBuilder;
use App\Support\Series\SeriesFilenameParser;
use App\ValueObjects\RelativeMediaPath;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class CreateOrReplaySeriesBatch
{
    public function __construct(
        private SeriesFilenameParser $parser,
        private JellyfinSeriesPathBuilder $pathBuilder,
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private UploadConfiguration $configuration,
        private CacheManager $cacheManager,
    ) {}

    /**
     * @param  array{idempotency_key:string,disk_id:string,items:list<array<string,mixed>>}  $input
     * @return array{batch: SeriesUploadBatch, idempotent_replay: bool}
     */
    public function execute(User $user, Series $series, array $input): array
    {
        try {
            $repository = $this->cacheManager->store('database');

            if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
                throw new UploadAdmissionException('upload_configuration_invalid', 'Upload configuration is unavailable.', 503);
            }

            $result = $repository->getStore()->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, 30)->block(
                2,
                fn (): array => DB::transaction(fn (): array => $this->admit($user, $series, $input), attempts: 3),
            );

            if (! is_array($result)
                || ! ($result['batch'] ?? null) instanceof SeriesUploadBatch
                || ! is_bool($result['idempotent_replay'] ?? null)
            ) {
                throw new UploadAdmissionException('admission_unavailable', 'Series batch admission is temporarily unavailable.', 503);
            }

            return ['batch' => $result['batch'], 'idempotent_replay' => $result['idempotent_replay']];
        } catch (LockTimeoutException $exception) {
            throw new UploadAdmissionException('admission_lock_timeout', 'Upload admission is busy. Please try again.', 503, $exception);
        } catch (UploadAdmissionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UploadAdmissionException('admission_unavailable', 'Series batch admission is temporarily unavailable.', 503, $exception);
        }
    }

    /**
     * @param  array{items:list<array<string,mixed>>}  $input
     * @return array<string, mixed>
     */
    public function preview(User $user, Series $series, array $input): array
    {
        try {
            $this->assertNoUnresolvedOperation($series);
            $series->loadMissing('seasons.episodes');
            $previewItems = array_map(fn (array $item): array => [
                ...$item,
                'last_modified_milliseconds' => null,
                'fingerprint_first_sha256' => str_repeat('0', 64),
                'fingerprint_last_sha256' => str_repeat('0', 64),
            ], $input['items']);
            $items = $this->canonicalItems($series, $user, $previewItems);
        } catch (UploadAdmissionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UploadAdmissionException('upload_request_invalid', 'The Series batch preview is invalid.', 422, $exception);
        }
        $declaredBytes = array_sum(array_column($items, 'declared_size'));
        $reservedBytes = $this->activeReservedBytes();
        $configuredRoots = collect($this->diskRegistry->forKind(MediaRootKind::Series))->keyBy('id');
        $candidateRoots = $series->home_disk_id === null
            ? $configuredRoots->sortKeys()->values()
            : collect([$configuredRoots->get($series->home_disk_id)]);
        $roots = [];

        foreach ($candidateRoots as $disk) {
            if ($disk === null) {
                $roots[] = $this->unavailableAssignedRoot($series->home_disk_id);

                continue;
            }

            $health = $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint());
            $activeReservedBytes = $reservedBytes[$disk->id] ?? 0;
            $projectedBytes = $health->usableBytes === null
                ? null
                : $health->usableBytes - $activeReservedBytes - $declaredBytes;
            $reasons = $health->toArray()['reasons'];

            if ($projectedBytes !== null && $projectedBytes < 0) {
                $reasons[] = [
                    'code' => 'insufficient_capacity',
                    'message' => 'The complete batch would exceed currently reservable capacity.',
                ];
            }

            $eligible = $health->healthy
                && $health->eligible
                && $projectedBytes !== null
                && $projectedBytes >= 0;
            $roots[] = [
                ...$health->toArray(),
                'status' => $eligible ? 'clear' : 'unavailable',
                'active_reserved_bytes' => $activeReservedBytes,
                'projected_usable_bytes' => $projectedBytes,
                'eligible' => $eligible,
                'reasons' => $reasons,
            ];
        }

        $recommendedDiskId = collect($roots)
            ->filter(fn (array $root): bool => ($root['eligible'] ?? false) === true)
            ->sort(function (array $left, array $right): int {
                $capacityComparison = ($right['projected_usable_bytes'] ?? PHP_INT_MIN)
                    <=> ($left['projected_usable_bytes'] ?? PHP_INT_MIN);

                return $capacityComparison !== 0 ? $capacityComparison : $left['id'] <=> $right['id'];
            })
            ->first()['id'] ?? null;

        return [
            'series' => ['id' => $series->getKey(), 'name' => $series->name, 'home_disk_id' => $series->home_disk_id],
            'declared_bytes' => $declaredBytes,
            'recommended_disk_id' => $recommendedDiskId,
            'can_start_batch' => $recommendedDiskId !== null,
            'items' => array_map(fn (array $item): array => [
                'source_basename' => $item['source_basename'],
                'series_episode_id' => $item['series_episode_id'],
                'episode_identity' => $item['episode_identity'],
                'episode_title' => $item['episode_title'],
                'target_relative_path' => $item['target_relative_path'],
                'declared_size' => $item['declared_size'],
                'replacement' => $item['replaces_media_file_id'] === null ? null : [
                    'media_file_id' => $item['replaces_media_file_id'],
                    'relative_path' => $item['replacement_relative_path'],
                    'size_bytes' => $item['replacement_size_bytes'],
                ],
            ], $items),
            'disks' => $roots,
        ];
    }

    /**
     * @param  array{idempotency_key:string,disk_id:string,items:list<array<string,mixed>>}  $input
     * @return array{batch: SeriesUploadBatch, idempotent_replay: bool}
     */
    private function admit(User $user, Series $series, array $input): array
    {
        $series = Series::query()->whereKey($series->getKey())->lockForUpdate()->firstOrFail();
        $this->assertNoUnresolvedOperation($series);
        $items = $this->canonicalItems($series, $user, $input['items']);
        $manifestHash = hash('sha256', json_encode($this->canonicalize($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $idempotencyKey = Str::lower($input['idempotency_key']);
        $existing = SeriesUploadBatch::query()
            ->whereBelongsTo($user)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->series_id !== $series->getKey()
                || $existing->disk_id !== $input['disk_id']
                || ! hash_equals($existing->manifest_hash, $manifestHash)
                || ! CanonicalJson::equivalent($existing->manifest, $items)
            ) {
                throw new UploadAdmissionException('idempotency_conflict', 'This batch key was already used with a different manifest.', 409);
            }

            return ['batch' => $existing->load('series', 'uploads.seriesEpisode.season'), 'idempotent_replay' => true];
        }

        if ($series->home_disk_id !== null && $series->home_disk_id !== $input['disk_id']) {
            throw new UploadAdmissionException('series_home_disk_conflict', 'This Series is permanently assigned to a different disk.', 409);
        }

        $disk = $this->diskRegistry->findRoot($input['disk_id'], MediaRootKind::Series);
        $health = $disk === null ? null : $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint());

        if ($disk === null || $health === null || ! $health->healthy || ! $health->eligible || $health->usableBytes === null) {
            throw new UploadAdmissionException('disk_unavailable', 'The selected Series root is unavailable.', 409);
        }

        $declaredBytes = array_sum(array_column($items, 'declared_size'));
        $reservedBytes = Upload::query()
            ->where('disk_id', $disk->id)
            ->whereIn('status', UploadStatus::capacityReservingValues())
            ->get(['declared_size', 'confirmed_offset'])
            ->sum(fn (Upload $upload): int => max($upload->declared_size - $upload->confirmed_offset, 0));

        if ($declaredBytes > max($health->usableBytes - $reservedBytes, 0)) {
            throw new UploadAdmissionException('insufficient_capacity', 'The complete batch no longer fits within reservable capacity.', 409);
        }

        foreach ($items as $item) {
            $relativePath = $item['target_relative_path'] ?? null;

            if (! is_string($relativePath)) {
                throw new UploadAdmissionException('upload_request_invalid', 'A generated Series destination is invalid.', 422);
            }

            $target = $this->pathGuard->resolveChild($disk->root, $relativePath);

            $targetIsActive = Upload::query()
                ->where('disk_id', $disk->id)
                ->where('root_kind', MediaRootKind::Series)
                ->where('target_relative_path', $relativePath)
                ->whereIn('status', [
                    UploadStatus::Pending->value,
                    UploadStatus::Uploading->value,
                    UploadStatus::Paused->value,
                    UploadStatus::Processing->value,
                    UploadStatus::Failed->value,
                ])
                ->exists();

            if ($targetIsActive || ($item['replaces_media_file_id'] === null && $this->filesystem->pathExists($target))) {
                throw new UploadAdmissionException('upload_conflict', 'A generated Series destination already exists.', 409);
            }
        }

        if ($series->home_disk_id === null) {
            $series->update(['home_disk_id' => $disk->id]);
        }

        $batch = SeriesUploadBatch::query()->create([
            'user_id' => $user->getKey(),
            'series_id' => $series->getKey(),
            'idempotency_key' => $idempotencyKey,
            'manifest' => $items,
            'manifest_hash' => $manifestHash,
            'disk_id' => $disk->id,
            'declared_bytes' => $declaredBytes,
            'confirmed_bytes' => 0,
            'status' => SeriesBatchStatus::Pending,
        ]);

        foreach ($items as $position => $item) {
            $uuid = (string) Str::uuid7();
            Upload::query()->create([
                'uuid' => $uuid,
                'user_id' => $user->getKey(),
                'media_item_id' => null,
                'series_episode_id' => $item['series_episode_id'],
                'series_upload_batch_id' => $batch->getKey(),
                'batch_position' => $position + 1,
                'status' => UploadStatus::Pending,
                'disk_id' => $disk->id,
                'root_kind' => MediaRootKind::Series,
                'target_relative_path' => $item['target_relative_path'],
                'staging_relative_path' => '.media-upload-manager/incoming/'.$uuid.'.part',
                'original_filename' => $item['source_basename'],
                'extension' => $item['extension'],
                'declared_size' => $item['declared_size'],
                'confirmed_offset' => 0,
                'last_modified_milliseconds' => $item['last_modified_milliseconds'],
                'fingerprint_first_sha256' => $item['fingerprint_first_sha256'],
                'fingerprint_last_sha256' => $item['fingerprint_last_sha256'],
                'token_abilities' => CreateOrReplayUploadReservation::TOKEN_ABILITIES,
                'last_activity_at' => now(),
                'expires_at' => now()->addSeconds($this->configuration->inactivitySeconds),
                'replaces_media_file_id' => $item['replaces_media_file_id'],
                'replacement_confirmed_at' => $item['replaces_media_file_id'] === null ? null : now(),
            ]);
        }

        return ['batch' => $batch->load('series', 'uploads.seriesEpisode.season'), 'idempotent_replay' => false];
    }

    private function assertNoUnresolvedOperation(Series $series): void
    {
        if (SeriesDeletionOperation::query()->where('series_id', $series->id)->whereNot('status', 'completed')->exists()
            || EpisodeRenameOperation::query()->whereIn('series_episode_id', $series->episodes()->select('series_episodes.id'))
                ->whereNot('status', 'completed')->exists()
        ) {
            throw new UploadAdmissionException(
                'series_operation_unresolved',
                'Resolve the Show rename or deletion operation before uploading more episodes.',
                409,
            );
        }
    }

    /** @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function canonicalItems(Series $series, User $user, array $items): array
    {
        $canonical = [];
        $sourceIdentities = [];
        $episodeIds = [];

        foreach ($items as $input) {
            $sourceIdentityValue = $input['source_identity'] ?? null;
            $episodeId = $input['series_episode_id'] ?? null;

            if (! is_string($sourceIdentityValue) || ! is_int($episodeId)) {
                throw new UploadAdmissionException('upload_request_invalid', 'A Series batch source identity is invalid.', 422);
            }

            $sourceIdentity = new RelativeMediaPath($sourceIdentityValue);
            $basename = basename($sourceIdentity->value);
            $parsed = $this->parser->parse($basename);
            $episode = SeriesEpisode::query()->with('season.series', 'currentMediaFile.sourceUpload')->find($episodeId);

            if (in_array($parsed->excludedReason, [
                'unsafe_filename',
                'unsupported_video',
                'known_extra',
                'multi_episode',
                'multipart_or_multiple_version',
            ], true)) {
                throw new UploadAdmissionException('unsafe_source_file', 'One or more source files are not safe single-episode videos.', 422);
            }

            if ($episode === null || $episode->season->series_id !== $series->getKey()) {
                throw new UploadAdmissionException('stale_episode_mapping', 'One or more source files no longer map to the selected TMDB episode.', 409);
            }

            if (isset($sourceIdentities[$sourceIdentity->value]) || isset($episodeIds[$episode->id])) {
                throw new UploadAdmissionException('duplicate_batch_item', 'A batch cannot contain duplicate sources or episodes.', 422);
            }

            $sourceIdentities[$sourceIdentity->value] = true;
            $episodeIds[$episode->id] = true;
            $path = $this->pathBuilder->build($episode, $basename);
            $replacementId = $input['replaces_media_file_id'] ?? null;
            $replacementConfirmed = (bool) ($input['replacement_confirmed'] ?? false);
            $current = $episode->currentMediaFile;

            if ($current !== null) {
                $owned = $current->sourceUpload?->user_id === $user->getKey();

                if ($replacementId !== $current->getKey() || ! $replacementConfirmed || (! $owned && ! $user->isAdministrator())) {
                    throw new UploadAdmissionException('replacement_conflict', 'The exact current episode primary must be explicitly authorized for replacement.', 409);
                }
            } elseif ($replacementId !== null || $replacementConfirmed) {
                throw new UploadAdmissionException('replacement_conflict', 'Replacement was requested for an episode without a current primary.', 409);
            }

            $canonical[] = [
                'source_identity' => $sourceIdentity->value,
                'source_basename' => $basename,
                'series_episode_id' => $episode->id,
                'season_number' => $episode->season->season_number,
                'episode_number' => $episode->episode_number,
                'episode_identity' => sprintf('S%02dE%02d', $episode->season->season_number, $episode->episode_number),
                'episode_title' => $episode->name,
                'target_relative_path' => $path->relativePath,
                'extension' => $path->extension,
                'declared_size' => $input['declared_size'],
                'last_modified_milliseconds' => $input['last_modified_milliseconds'] ?? null,
                'fingerprint_first_sha256' => $input['fingerprint_first_sha256'],
                'fingerprint_last_sha256' => $input['fingerprint_last_sha256'],
                'replaces_media_file_id' => $replacementId,
                'replacement_relative_path' => $current?->relative_path,
                'replacement_size_bytes' => $current?->size_bytes,
            ];
        }

        usort($canonical, fn (array $left, array $right): int => [$left['season_number'], $left['episode_number']] <=> [$right['season_number'], $right['episode_number']]);

        return $canonical;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @return array<string, int> */
    private function activeReservedBytes(): array
    {
        $reservedBytes = [];
        $uploads = Upload::query()
            ->whereIn('status', UploadStatus::capacityReservingValues())
            ->get(['disk_id', 'declared_size', 'confirmed_offset']);

        foreach ($uploads as $upload) {
            $remainingBytes = max($upload->declared_size - $upload->confirmed_offset, 0);
            $currentBytes = $reservedBytes[$upload->disk_id] ?? 0;
            $reservedBytes[$upload->disk_id] = $currentBytes > PHP_INT_MAX - $remainingBytes
                ? PHP_INT_MAX
                : $currentBytes + $remainingBytes;
        }

        return $reservedBytes;
    }

    /** @return array<string, mixed> */
    private function unavailableAssignedRoot(?string $diskId): array
    {
        return [
            'id' => $diskId ?? 'unavailable',
            'label' => $diskId ?? 'Unavailable disk',
            'status' => 'unavailable',
            'health' => 'unhealthy',
            'total_bytes' => null,
            'free_bytes' => null,
            'safety_reserve_bytes' => 0,
            'usable_bytes' => null,
            'active_reserved_bytes' => 0,
            'projected_usable_bytes' => null,
            'eligible' => false,
            'reasons' => [[
                'code' => 'configured_root_missing',
                'message' => 'This Show\'s assigned storage root is no longer configured.',
            ]],
        ];
    }
}
