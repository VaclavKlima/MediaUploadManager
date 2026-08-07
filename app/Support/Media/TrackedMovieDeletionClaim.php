<?php

namespace App\Support\Media;

use InvalidArgumentException;

final readonly class TrackedMovieDeletionClaim
{
    private function __construct(
        public int $mediaItemId,
        public int $actorUserId,
        public string $title,
        public ?int $mediaFileId = null,
        public ?int $sourceUploadId = null,
        public ?string $diskId = null,
        public ?string $relativePath = null,
        public ?int $sizeBytes = null,
        public ?int $deviceId = null,
        public ?int $inodeId = null,
    ) {}

    public static function forPrimary(
        int $mediaItemId,
        int $actorUserId,
        string $title,
        int $mediaFileId,
        int $sourceUploadId,
        string $diskId,
        string $relativePath,
        int $sizeBytes,
        int $deviceId,
        int $inodeId,
    ): self {
        return new self(
            mediaItemId: $mediaItemId,
            actorUserId: $actorUserId,
            title: $title,
            mediaFileId: $mediaFileId,
            sourceUploadId: $sourceUploadId,
            diskId: $diskId,
            relativePath: $relativePath,
            sizeBytes: $sizeBytes,
            deviceId: $deviceId,
            inodeId: $inodeId,
        );
    }

    public static function forOrphan(int $mediaItemId, int $actorUserId, string $title): self
    {
        return new self($mediaItemId, $actorUserId, $title);
    }

    /** @param array<string, mixed> $claim */
    public static function fromArray(array $claim): self
    {
        if (($claim['version'] ?? null) !== 1
            || ! is_int($claim['media_item_id'] ?? null)
            || ! is_int($claim['actor_user_id'] ?? null)
            || ! is_string($claim['title'] ?? null)
        ) {
            throw new InvalidArgumentException('The tracked movie deletion claim is invalid.');
        }

        $mediaFileId = $claim['media_file_id'] ?? null;
        $sourceUploadId = $claim['source_upload_id'] ?? null;
        $diskId = $claim['disk_id'] ?? null;
        $relativePath = $claim['relative_path'] ?? null;
        $sizeBytes = $claim['size_bytes'] ?? null;
        $deviceId = $claim['device_id'] ?? null;
        $inodeId = $claim['inode_id'] ?? null;
        $hasNoFile = $mediaFileId === null
            && $sourceUploadId === null
            && $diskId === null
            && $relativePath === null
            && $sizeBytes === null
            && $deviceId === null
            && $inodeId === null;
        $hasCompleteFile = is_int($mediaFileId)
            && is_int($sourceUploadId)
            && is_string($diskId)
            && is_string($relativePath)
            && is_int($sizeBytes)
            && is_int($deviceId)
            && is_int($inodeId);

        if (! $hasNoFile && ! $hasCompleteFile) {
            throw new InvalidArgumentException('The tracked movie deletion claim is invalid.');
        }

        return new self(
            mediaItemId: $claim['media_item_id'],
            actorUserId: $claim['actor_user_id'],
            title: $claim['title'],
            mediaFileId: $hasCompleteFile ? $mediaFileId : null,
            sourceUploadId: $hasCompleteFile ? $sourceUploadId : null,
            diskId: $hasCompleteFile ? $diskId : null,
            relativePath: $hasCompleteFile ? $relativePath : null,
            sizeBytes: $hasCompleteFile ? $sizeBytes : null,
            deviceId: $hasCompleteFile ? $deviceId : null,
            inodeId: $hasCompleteFile ? $inodeId : null,
        );
    }

    public function hasPrimary(): bool
    {
        return $this->mediaFileId !== null;
    }

    /**
     * @return array{
     *     media_file_id: int,
     *     source_upload_id: int,
     *     disk_id: string,
     *     relative_path: string,
     *     size_bytes: int,
     *     device_id: int,
     *     inode_id: int
     * }
     */
    public function primaryIdentity(): array
    {
        if ($this->mediaFileId === null
            || $this->sourceUploadId === null
            || $this->diskId === null
            || $this->relativePath === null
            || $this->sizeBytes === null
            || $this->deviceId === null
            || $this->inodeId === null
        ) {
            throw new InvalidArgumentException('The tracked movie deletion claim has no primary identity.');
        }

        return [
            'media_file_id' => $this->mediaFileId,
            'source_upload_id' => $this->sourceUploadId,
            'disk_id' => $this->diskId,
            'relative_path' => $this->relativePath,
            'size_bytes' => $this->sizeBytes,
            'device_id' => $this->deviceId,
            'inode_id' => $this->inodeId,
        ];
    }

    /**
     * @return array{
     *     version: 1,
     *     media_item_id: int,
     *     actor_user_id: int,
     *     title: string,
     *     media_file_id: int|null,
     *     source_upload_id: int|null,
     *     disk_id: string|null,
     *     relative_path: string|null,
     *     size_bytes: int|null,
     *     device_id: int|null,
     *     inode_id: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'media_item_id' => $this->mediaItemId,
            'actor_user_id' => $this->actorUserId,
            'title' => $this->title,
            'media_file_id' => $this->mediaFileId,
            'source_upload_id' => $this->sourceUploadId,
            'disk_id' => $this->diskId,
            'relative_path' => $this->relativePath,
            'size_bytes' => $this->sizeBytes,
            'device_id' => $this->deviceId,
            'inode_id' => $this->inodeId,
        ];
    }
}
