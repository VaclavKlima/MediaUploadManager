<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\SeriesEpisode;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\RelocationVerificationException;

final readonly class SeriesLibraryRelocationVerifier
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $healthChecker,
        private MediaPathGuard $pathGuard,
        private MediaFilesystem $filesystem,
        private UploadConfiguration $uploadConfiguration,
    ) {}

    /** @return array<string, int|string|null> */
    public function prove(
        LibraryFinding $discovered,
        LibraryFinding $missing,
        int $seriesEpisodeId,
        ?string $destinationRelativePath = null,
    ): array {
        if ($discovered->id === $missing->id
            || $discovered->library_scan_id !== $missing->library_scan_id
            || $discovered->root_kind !== MediaRootKind::Series
            || $missing->root_kind !== MediaRootKind::Series
            || $discovered->kind !== 'discovered'
            || $missing->kind !== 'missing'
            || $discovered->resolved_at !== null
            || $missing->resolved_at !== null
            || $missing->series_episode_id !== $seriesEpisodeId
            || $missing->media_file_id === null
        ) {
            throw $this->failure('stale_pair', 'The Show episode relocation pair is no longer valid.');
        }

        $episode = SeriesEpisode::query()->with('season.series')->find($seriesEpisodeId);
        $mediaFile = MediaFile::query()->find($missing->media_file_id);

        if (! $episode instanceof SeriesEpisode
            || ! $mediaFile instanceof MediaFile
            || $episode->current_media_file_id !== $mediaFile->id
            || $mediaFile->series_episode_id !== $episode->id
            || $mediaFile->root_kind !== MediaRootKind::Series
            || $mediaFile->active_path_key !== MediaFile::activePathKey($mediaFile->disk_id, $mediaFile->relative_path, MediaRootKind::Series)
            || $mediaFile->removed_at !== null
            || $missing->disk_id !== $mediaFile->disk_id
            || $missing->relative_path !== $mediaFile->relative_path
            || $missing->size_bytes !== $mediaFile->size_bytes
        ) {
            throw $this->failure('changed_primary', 'The tracked Show episode primary changed after the scan.');
        }

        if (LibraryFinding::query()
            ->where('library_scan_id', $discovered->library_scan_id)
            ->whereKeyNot($discovered->id)
            ->where('root_kind', MediaRootKind::Series)
            ->where('kind', 'discovered')
            ->where('tmdb_id', $discovered->tmdb_id)
            ->where('season_number', $discovered->season_number)
            ->where('episode_number', $discovered->episode_number)
            ->whereNull('resolved_at')
            ->exists()
        ) {
            throw $this->failure('duplicate_finding', 'Multiple discovered files identify the same Show episode.');
        }

        $foundDisk = $this->healthyDisk($discovered->disk_id);
        $trackedDisk = $this->healthyDisk($mediaFile->disk_id);

        if ($foundDisk->id !== $trackedDisk->id
            || ($episode->season->series->home_disk_id !== null
                && $episode->season->series->home_disk_id !== $foundDisk->id)
        ) {
            throw $this->failure('home_disk_mismatch', 'A moved Show episode must remain on its immutable home Series root.');
        }

        $foundPath = $this->pathGuard->resolveChild($foundDisk->root, $discovered->relative_path);
        $trackedPath = $this->pathGuard->resolveChild($trackedDisk->root, $mediaFile->relative_path);
        $destinationRelativePath ??= $discovered->destination_relative_path;

        if ($destinationRelativePath === null) {
            throw $this->failure('destination_missing', 'The canonical Show episode destination is missing.');
        }

        $destinationPath = $this->pathGuard->resolveChild($foundDisk->root, $destinationRelativePath);
        $this->assertFoundSnapshot($discovered, $foundPath);

        if ($this->filesystem->pathExists($trackedPath)) {
            throw $this->failure('old_path_returned', 'The tracked Show episode file returned to its original path.');
        }

        if ($foundPath !== $destinationPath && $this->filesystem->pathExists($destinationPath)) {
            throw $this->failure('destination_occupied', 'The canonical Show episode destination is occupied.');
        }

        if (MediaFile::query()
            ->where('active_path_key', MediaFile::activePathKey($foundDisk->id, $destinationRelativePath, MediaRootKind::Series))
            ->whereKeyNot($mediaFile->id)
            ->exists()
        ) {
            throw $this->failure('destination_database_conflict', 'Another media record claims the Show episode destination.');
        }

        if (Upload::query()
            ->where('series_episode_id', $episode->id)
            ->when($mediaFile->source_upload_id !== null, fn ($query) => $query->whereKeyNot($mediaFile->source_upload_id))
            ->whereIn('status', [
                UploadStatus::Pending->value,
                UploadStatus::Uploading->value,
                UploadStatus::Paused->value,
                UploadStatus::Processing->value,
                UploadStatus::Failed->value,
            ])
            ->exists()
        ) {
            throw $this->failure('active_upload', 'Another upload is active for this Show episode.');
        }

        return $this->proveBytes($mediaFile, $foundPath);
    }

    /**
     * @param  array<string, mixed>  $proof
     * @return array{type: 'inode', size_bytes: int, device_id: int, inode_id: int}|array{type: 'upload_fingerprint', size_bytes: int, window_bytes: int, first_sha256: string, last_sha256: string, source_upload_id: int|null}
     */
    public function assertProof(string $path, array $proof): array
    {
        if (($proof['type'] ?? null) === 'upload_fingerprint') {
            return $this->proveFingerprint(
                $path,
                $proof['size_bytes'] ?? null,
                $proof['window_bytes'] ?? null,
                $proof['first_sha256'] ?? null,
                $proof['last_sha256'] ?? null,
                is_int($proof['source_upload_id'] ?? null) ? $proof['source_upload_id'] : null,
            );

        }

        if (($proof['type'] ?? null) !== 'inode'
            || ! is_int($proof['size_bytes'] ?? null)
            || ! is_int($proof['device_id'] ?? null)
            || ! is_int($proof['inode_id'] ?? null)
            || $this->filesystem->fileSize($path) !== $proof['size_bytes']
            || $this->filesystem->deviceId($path) !== $proof['device_id']
            || $this->filesystem->inodeId($path) !== $proof['inode_id']
        ) {
            throw $this->failure('inode_proof_failed', 'The file does not match the durable Show import inode claim.');
        }

        return [
            'type' => 'inode',
            'size_bytes' => $proof['size_bytes'],
            'device_id' => $proof['device_id'],
            'inode_id' => $proof['inode_id'],
        ];
    }

    /** @return array<string, int|string|null> */
    private function proveBytes(MediaFile $mediaFile, string $foundPath): array
    {
        if ($mediaFile->source_upload_id !== null) {
            $upload = Upload::query()->find($mediaFile->source_upload_id);

            if (! $upload instanceof Upload
                || $upload->series_episode_id !== $mediaFile->series_episode_id
                || $upload->declared_size !== $mediaFile->size_bytes
            ) {
                throw $this->failure('upload_provenance_missing', 'The source Show upload provenance is incomplete.');
            }

            return $this->proveFingerprint(
                $foundPath,
                $mediaFile->size_bytes,
                $this->uploadConfiguration->fingerprintWindowBytes,
                $upload->fingerprint_first_sha256,
                $upload->fingerprint_last_sha256,
                $upload->id,
            );
        }

        $proof = is_array($mediaFile->import_provenance)
            ? ($mediaFile->import_provenance['relocation_proof'] ?? null)
            : null;

        if (! is_array($proof)) {
            throw $this->failure('import_provenance_missing', 'The Show import provenance is incomplete.');
        }

        $proof = $this->stringKeyedArray($proof);

        return $this->assertProof($foundPath, $proof);
    }

    /** @return array{type:'upload_fingerprint',size_bytes:int,window_bytes:int,first_sha256:string,last_sha256:string,source_upload_id:int|null} */
    private function proveFingerprint(
        string $path,
        mixed $sizeBytes,
        mixed $windowBytes,
        mixed $firstSha256,
        mixed $lastSha256,
        ?int $sourceUploadId,
    ): array {
        if (! is_int($sizeBytes)
            || ! is_int($windowBytes)
            || $windowBytes <= 0
            || ! is_string($firstSha256)
            || ! is_string($lastSha256)
            || $this->filesystem->fileSize($path) !== $sizeBytes
        ) {
            throw $this->failure('fingerprint_provenance_missing', 'The Show upload fingerprint provenance is incomplete.');
        }

        $ranges = new FileFingerprintRanges($sizeBytes, $windowBytes);
        $actualFirst = $this->filesystem->sha256Range($path, $ranges->firstOffset, $ranges->firstLength);
        $actualLast = $this->filesystem->sha256Range($path, $ranges->lastOffset, $ranges->lastLength);

        if ($actualFirst === null
            || $actualLast === null
            || ! hash_equals($firstSha256, $actualFirst)
            || ! hash_equals($lastSha256, $actualLast)
        ) {
            throw $this->failure('fingerprint_proof_failed', 'The found file does not match the Show upload fingerprints.');
        }

        return [
            'type' => 'upload_fingerprint',
            'size_bytes' => $sizeBytes,
            'window_bytes' => $windowBytes,
            'first_sha256' => $firstSha256,
            'last_sha256' => $lastSha256,
            'source_upload_id' => $sourceUploadId,
        ];
    }

    private function assertFoundSnapshot(LibraryFinding $finding, string $path): void
    {
        if (! is_int($finding->size_bytes)
            || ! is_int($finding->device_id)
            || ! is_int($finding->inode_id)
            || ! $this->filesystem->isRegularFile($path)
            || $this->filesystem->fileSize($path) !== $finding->size_bytes
            || $this->filesystem->deviceId($path) !== $finding->device_id
            || $this->filesystem->inodeId($path) !== $finding->inode_id
        ) {
            throw $this->failure('source_changed', 'The found Show episode file no longer matches its scan snapshot.');
        }
    }

    private function healthyDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->findRoot($diskId, MediaRootKind::Series);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw $this->failure('disk_unavailable', 'A Show relocation disk is unavailable or its marker identity changed.');
        }

        return $disk;
    }

    private function failure(string $reason, string $message): RelocationVerificationException
    {
        return new RelocationVerificationException($reason, $message);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw $this->failure('import_provenance_invalid', 'The Show import provenance has an invalid key.');
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
