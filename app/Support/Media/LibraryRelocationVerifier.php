<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\LibraryFinding;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\RelocationVerificationException;

class LibraryRelocationVerifier
{
    public function __construct(
        private readonly ConfiguredDiskRegistry $diskRegistry,
        private readonly MediaDiskHealthChecker $healthChecker,
        private readonly MediaPathGuard $pathGuard,
        private readonly MediaFilesystem $filesystem,
        private readonly UploadConfiguration $uploadConfiguration,
    ) {}

    /**
     * @return array{type: 'inode', size_bytes: int, device_id: int, inode_id: int}|array{type: 'upload_fingerprint', size_bytes: int, window_bytes: int, first_sha256: string, last_sha256: string, source_upload_id: int|null}
     */
    public function prove(
        LibraryFinding $discovered,
        LibraryFinding $missing,
        int $tmdbId,
        ?string $destinationRelativePath = null,
    ): array {
        if ($discovered->id === $missing->id
            || $discovered->root_kind !== MediaRootKind::Movies
            || $missing->root_kind !== MediaRootKind::Movies
            || $discovered->library_scan_id !== $missing->library_scan_id
            || $discovered->kind !== 'discovered'
            || $discovered->resolved_at !== null
            || $missing->kind !== 'missing'
            || $missing->resolved_at !== null
            || $missing->media_item_id === null
            || $missing->media_file_id === null
        ) {
            throw $this->failure('stale_pair', 'The relocation pair is no longer valid.');
        }

        $mediaItem = MediaItem::query()->find($missing->media_item_id);
        $mediaFile = MediaFile::query()->find($missing->media_file_id);

        if (! $mediaItem instanceof MediaItem
            || ! $mediaFile instanceof MediaFile
            || $mediaItem->tmdb_id !== $tmdbId
            || $mediaItem->current_media_file_id !== $mediaFile->id
            || $mediaFile->media_item_id !== $mediaItem->id
            || $mediaFile->active_path_key !== MediaFile::activePathKey($mediaFile->disk_id, $mediaFile->relative_path)
            || $mediaFile->removed_at !== null
            || $missing->disk_id !== $mediaFile->disk_id
            || $missing->relative_path !== $mediaFile->relative_path
            || $missing->size_bytes !== $mediaFile->size_bytes
        ) {
            throw $this->failure('changed_primary', 'The tracked current primary changed after the scan.');
        }

        if (LibraryFinding::query()
            ->where('library_scan_id', $discovered->library_scan_id)
            ->where('root_kind', MediaRootKind::Movies)
            ->whereKeyNot($discovered->id)
            ->where('kind', 'discovered')
            ->where('tmdb_id', $tmdbId)
            ->whereNull('resolved_at')
            ->exists()
        ) {
            throw $this->failure('duplicate_finding', 'Multiple discovered files identify the same movie.');
        }

        $foundDisk = $this->healthyDisk($discovered->disk_id);
        $trackedDisk = $this->healthyDisk($mediaFile->disk_id);
        $foundPath = $this->pathGuard->resolveChild($foundDisk->root, $discovered->relative_path);
        $trackedPath = $this->pathGuard->resolveChild($trackedDisk->root, $mediaFile->relative_path);
        $destinationRelativePath ??= $discovered->destination_relative_path;

        if ($destinationRelativePath === null) {
            throw $this->failure('destination_missing', 'The canonical relocation destination is missing.');
        }

        $destinationPath = $this->pathGuard->resolveChild($foundDisk->root, $destinationRelativePath);

        $this->assertFoundSnapshot($discovered, $foundPath);

        if ($this->filesystem->pathExists($trackedPath)) {
            throw $this->failure('old_path_returned', 'The tracked file has returned to its original path.');
        }

        if ($foundPath !== $destinationPath && $this->filesystem->pathExists($destinationPath)) {
            throw $this->failure('destination_occupied', 'The canonical relocation destination is occupied.');
        }

        if (MediaFile::query()
            ->where('active_path_key', MediaFile::activePathKey($foundDisk->id, $destinationRelativePath))
            ->whereKeyNot($mediaFile->id)
            ->exists()
        ) {
            throw $this->failure('destination_database_conflict', 'Another media record claims the relocation destination.');
        }

        $this->assertDestinationDirectory(dirname($destinationPath), $foundPath, $destinationPath);

        if (Upload::query()
            ->where('media_item_id', $mediaItem->id)
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
            throw $this->failure('active_upload', 'Another upload is active for this movie.');
        }

        return $this->proveBytes($mediaFile, $foundPath);
    }

    /** @param array<string, mixed> $proof */
    public function assertProof(string $path, array $proof): void
    {
        if (($proof['type'] ?? null) === 'upload_fingerprint') {
            if (! is_int($proof['size_bytes'] ?? null)) {
                throw $this->failure('fingerprint_provenance_missing', 'The upload fingerprint provenance is incomplete.');
            }

            $this->proveFingerprint(
                $path,
                $proof['size_bytes'],
                $proof['window_bytes'] ?? null,
                $proof['first_sha256'] ?? null,
                $proof['last_sha256'] ?? null,
                is_int($proof['source_upload_id'] ?? null) ? $proof['source_upload_id'] : null,
            );

            return;
        }

        if (($proof['type'] ?? null) !== 'inode'
            || ! is_int($proof['size_bytes'] ?? null)
            || ! is_int($proof['device_id'] ?? null)
            || ! is_int($proof['inode_id'] ?? null)
            || $this->filesystem->fileSize($path) !== $proof['size_bytes']
            || $this->filesystem->deviceId($path) !== $proof['device_id']
            || $this->filesystem->inodeId($path) !== $proof['inode_id']
        ) {
            throw $this->failure('inode_proof_failed', 'The file does not match the durable import inode claim.');
        }
    }

    private function healthyDisk(string $diskId): ConfiguredMediaDisk
    {
        $disk = $this->diskRegistry->find($diskId);

        if ($disk === null || ! $this->healthChecker->check($disk, $this->diskRegistry->requiresMountpoint())->healthy) {
            throw $this->failure('disk_unavailable', 'A relocation disk is unavailable or its marker identity changed.');
        }

        return $disk;
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
            throw $this->failure('source_changed', 'The found file no longer matches its scan snapshot.');
        }
    }

    private function assertDestinationDirectory(string $directory, string $sourcePath, string $destinationPath): void
    {
        if (! $this->filesystem->pathExists($directory)) {
            return;
        }

        if (! $this->filesystem->isDirectory($directory) || $this->filesystem->isSymbolicLink($directory)) {
            throw $this->failure('destination_unsafe', 'The canonical relocation directory is unsafe.');
        }

        $entries = @scandir($directory);

        if (! is_array($entries)) {
            throw $this->failure('destination_unreadable', 'The canonical relocation directory cannot be inspected.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if ($path === $sourcePath || $path === $destinationPath) {
                continue;
            }

            if ($this->filesystem->isSymbolicLink($path)) {
                throw $this->failure('destination_symlink', 'The canonical relocation directory contains a symbolic link.');
            }

            if ($this->filesystem->isRegularFile($path)
                && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)
            ) {
                throw $this->failure('destination_version', 'The canonical relocation directory contains another movie version.');
            }
        }
    }

    /**
     * @return array{type: 'inode', size_bytes: int, device_id: int, inode_id: int}|array{type: 'upload_fingerprint', size_bytes: int, window_bytes: int, first_sha256: string, last_sha256: string, source_upload_id: int|null}
     */
    private function proveBytes(MediaFile $mediaFile, string $foundPath): array
    {
        if ($mediaFile->source_upload_id !== null) {
            $upload = Upload::query()->find($mediaFile->source_upload_id);

            if (! $upload instanceof Upload
                || $upload->media_item_id !== $mediaFile->media_item_id
                || $upload->declared_size !== $mediaFile->size_bytes
            ) {
                throw $this->failure('upload_provenance_missing', 'The source upload provenance is incomplete.');
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

        $provenance = $mediaFile->import_provenance;
        $proof = is_array($provenance) ? ($provenance['relocation_proof'] ?? null) : null;

        if (! is_array($proof)) {
            $proof = $this->originalImportProof($provenance);
        }

        if (($proof['type'] ?? null) === 'upload_fingerprint') {
            return $this->proveFingerprint(
                $foundPath,
                $mediaFile->size_bytes,
                $proof['window_bytes'] ?? null,
                $proof['first_sha256'] ?? null,
                $proof['last_sha256'] ?? null,
                is_int($proof['source_upload_id'] ?? null) ? $proof['source_upload_id'] : null,
            );
        }

        if (($proof['type'] ?? null) !== 'inode'
            || ($proof['size_bytes'] ?? null) !== $mediaFile->size_bytes
            || ! is_int($proof['device_id'] ?? null)
            || ! is_int($proof['inode_id'] ?? null)
            || $this->filesystem->fileSize($foundPath) !== $proof['size_bytes']
            || $this->filesystem->deviceId($foundPath) !== $proof['device_id']
            || $this->filesystem->inodeId($foundPath) !== $proof['inode_id']
        ) {
            throw $this->failure('inode_proof_failed', 'The found file does not match the durable import inode claim.');
        }

        return [
            'type' => 'inode',
            'size_bytes' => $proof['size_bytes'],
            'device_id' => $proof['device_id'],
            'inode_id' => $proof['inode_id'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $provenance
     * @return array{type: 'inode', size_bytes: int, device_id: int, inode_id: int}|null
     */
    private function originalImportProof(?array $provenance): ?array
    {
        $findingId = $provenance['library_finding_id'] ?? null;

        if (! is_int($findingId)) {
            return null;
        }

        $claim = LibraryFinding::query()->find($findingId)?->operation_claim;

        if (! is_array($claim)
            || ($claim['type'] ?? null) !== 'import'
            || ! is_int($claim['size_bytes'] ?? null)
            || ! is_int($claim['device_id'] ?? null)
            || ! is_int($claim['inode_id'] ?? null)
        ) {
            return null;
        }

        return [
            'type' => 'inode',
            'size_bytes' => $claim['size_bytes'],
            'device_id' => $claim['device_id'],
            'inode_id' => $claim['inode_id'],
        ];
    }

    /**
     * @return array{type: 'upload_fingerprint', size_bytes: int, window_bytes: int, first_sha256: string, last_sha256: string, source_upload_id: int|null}
     */
    private function proveFingerprint(
        string $path,
        int $sizeBytes,
        mixed $windowBytes,
        mixed $firstSha256,
        mixed $lastSha256,
        ?int $sourceUploadId,
    ): array {
        if (! is_int($windowBytes)
            || $windowBytes <= 0
            || ! is_string($firstSha256)
            || ! is_string($lastSha256)
            || $this->filesystem->fileSize($path) !== $sizeBytes
        ) {
            throw $this->failure('fingerprint_provenance_missing', 'The upload fingerprint provenance is incomplete.');
        }

        $ranges = new FileFingerprintRanges($sizeBytes, $windowBytes);
        $actualFirst = $this->filesystem->sha256Range($path, $ranges->firstOffset, $ranges->firstLength);
        $actualLast = $this->filesystem->sha256Range($path, $ranges->lastOffset, $ranges->lastLength);

        if ($actualFirst === null
            || $actualLast === null
            || ! hash_equals($firstSha256, $actualFirst)
            || ! hash_equals($lastSha256, $actualLast)
        ) {
            throw $this->failure('fingerprint_proof_failed', 'The found file does not match the source upload fingerprints.');
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

    private function failure(string $reason, string $message): RelocationVerificationException
    {
        return new RelocationVerificationException($reason, $message);
    }
}
