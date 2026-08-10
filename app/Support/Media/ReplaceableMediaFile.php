<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class ReplaceableMediaFile implements JsonSerializable
{
    public function __construct(
        public int $id,
        public ?int $sourceUploadId,
        public string $diskId,
        public string $diskLabel,
        public string $relativePath,
        public int $sizeBytes,
        public string $finalizedAt,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     disk: array{id: string, label: string},
     *     relative_path: string,
     *     size_bytes: int,
     *     finalized_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'disk' => [
                'id' => $this->diskId,
                'label' => $this->diskLabel,
            ],
            'relative_path' => $this->relativePath,
            'size_bytes' => $this->sizeBytes,
            'finalized_at' => $this->finalizedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
