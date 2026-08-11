<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\FfprobeMediaValidator;
use App\Support\Media\MediaDiskHealthChecker;
use App\Support\Media\MediaPathGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

#[Signature('media:metadata:backfill-dynamic-range')]
#[Description('Safely add missing dynamic-range metadata to current media files')]
class BackfillDynamicRangeMetadataCommand extends Command
{
    private const DYNAMIC_RANGES = [
        'dolby_vision',
        'hdr10_plus',
        'hdr10',
        'hlg',
        'sdr',
        'unknown',
    ];

    public function handle(
        ConfiguredDiskRegistry $diskRegistry,
        MediaDiskHealthChecker $healthChecker,
        MediaPathGuard $pathGuard,
        MediaFilesystem $filesystem,
        FfprobeMediaValidator $validator,
    ): int {
        try {
            $diskRegistry->all();
        } catch (MediaConfigurationException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $enriched = 0;
        $skipped = 0;
        $failed = 0;
        $diskHealth = [];

        MediaFile::query()
            ->select([
                'id',
                'disk_id',
                'relative_path',
                'size_bytes',
                'video_metadata',
            ])
            ->whereHas('currentForMediaItem')
            ->chunkById(100, function (Collection $mediaFiles) use (
                $diskRegistry,
                $healthChecker,
                $pathGuard,
                $filesystem,
                $validator,
                &$diskHealth,
                &$enriched,
                &$skipped,
                &$failed,
            ): void {
                foreach ($mediaFiles as $mediaFile) {
                    try {
                        if ($this->isEnriched($mediaFile->video_metadata)) {
                            $skipped++;

                            continue;
                        }

                        $disk = $diskRegistry->find($mediaFile->disk_id);

                        if ($disk === null) {
                            throw new RuntimeException('The configured media disk is unavailable.');
                        }

                        if (! array_key_exists($disk->id, $diskHealth)) {
                            $diskHealth[$disk->id] = $healthChecker
                                ->check($disk, $diskRegistry->requiresMountpoint())
                                ->healthy;
                        }

                        if (! $diskHealth[$disk->id]) {
                            throw new RuntimeException('The configured media disk is unhealthy.');
                        }

                        $path = $pathGuard->resolveChild($disk->root, $mediaFile->relative_path);

                        if (! $filesystem->isRegularFile($path)) {
                            throw new RuntimeException('The tracked path is not a regular file.');
                        }

                        if ($filesystem->fileSize($path) !== $mediaFile->size_bytes) {
                            throw new RuntimeException('The tracked file size does not match the stored size.');
                        }

                        $deviceId = $filesystem->deviceId($path);
                        $inodeId = $filesystem->inodeId($path);

                        if ($deviceId === null || $inodeId === null) {
                            throw new RuntimeException('The tracked file identity is unavailable.');
                        }

                        $probe = $validator->probe($path);
                        $recheckedPath = $pathGuard->resolveChild($disk->root, $mediaFile->relative_path);

                        if (! $filesystem->isRegularFile($recheckedPath)
                            || $filesystem->fileSize($recheckedPath) !== $mediaFile->size_bytes
                            || $filesystem->deviceId($recheckedPath) !== $deviceId
                            || $filesystem->inodeId($recheckedPath) !== $inodeId
                        ) {
                            throw new RuntimeException('The tracked file changed while it was being probed.');
                        }

                        $mediaFile->refresh();

                        if (! $mediaFile->currentForMediaItem()->exists()) {
                            $skipped++;

                            continue;
                        }

                        if ($this->isEnriched($mediaFile->video_metadata)) {
                            $skipped++;

                            continue;
                        }

                        $metadata = $this->enrichedMetadata(
                            $mediaFile->video_metadata,
                            $probe['video'],
                        );

                        if (! $mediaFile->addMissingDynamicRangeMetadata($metadata)) {
                            $skipped++;

                            continue;
                        }

                        $enriched++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->components->warn(
                            'Media file '.$mediaFile->id.' failed: '.$exception->getMessage(),
                        );
                    }
                }
            });

        $this->components->info(
            "Dynamic-range backfill complete: {$enriched} enriched, {$skipped} skipped, {$failed} failed.",
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<mixed> $metadata */
    private function isEnriched(array $metadata): bool
    {
        $streams = array_is_list($metadata) ? $metadata : [$metadata];

        if ($streams === []) {
            return false;
        }

        foreach ($streams as $stream) {
            if (! is_array($stream)
                || array_is_list($stream)
                || ! in_array($stream['dynamic_range'] ?? null, self::DYNAMIC_RANGES, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $existingMetadata
     * @param  list<array<string, mixed>>  $probedMetadata
     * @return array<mixed>
     */
    private function enrichedMetadata(array $existingMetadata, array $probedMetadata): array
    {
        $existingIsList = array_is_list($existingMetadata);
        $existingStreams = $existingIsList ? $existingMetadata : [$existingMetadata];

        if ($existingStreams === [] || $probedMetadata === []) {
            throw new RuntimeException('The stored video metadata cannot be safely matched to the probe.');
        }

        $probedByIndex = [];

        foreach ($probedMetadata as $stream) {
            $index = $stream['index'] ?? null;

            if (! is_int($index) || $index < 0) {
                throw new RuntimeException('The probed video metadata has an invalid stream index.');
            }

            $probedByIndex[$index] = $stream;
        }

        foreach ($existingStreams as $position => $stream) {
            if (! is_array($stream) || array_is_list($stream)) {
                throw new RuntimeException('The stored video metadata is malformed.');
            }

            if (array_key_exists('dynamic_range', $stream)) {
                if (! in_array($stream['dynamic_range'], self::DYNAMIC_RANGES, true)) {
                    throw new RuntimeException('Existing dynamic-range metadata is invalid and immutable.');
                }

                continue;
            }

            $index = $stream['index'] ?? null;
            $probedStream = is_int($index) && $index >= 0
                ? ($probedByIndex[$index] ?? null)
                : ($probedMetadata[$position] ?? null);
            $dynamicRange = is_array($probedStream) ? ($probedStream['dynamic_range'] ?? null) : null;

            if (! in_array($dynamicRange, self::DYNAMIC_RANGES, true)) {
                throw new RuntimeException('The stored video stream could not be matched safely.');
            }

            $stream['dynamic_range'] = $dynamicRange;
            $existingStreams[$position] = $stream;
        }

        return $existingIsList ? $existingStreams : $existingStreams[0];
    }
}
