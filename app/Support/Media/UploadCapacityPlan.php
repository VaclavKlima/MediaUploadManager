<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class UploadCapacityPlan implements JsonSerializable
{
    /**
     * @param  list<MovieConflictBlocker>  $blockers
     * @param  list<DiskCapacityPlan>  $disks
     */
    public function __construct(
        public int $declaredSize,
        public bool $canStartNewUpload,
        public bool $canReplaceCurrentPrimary,
        public ?ReplaceableMediaFile $replaceable,
        public array $blockers,
        public array $disks,
        public ?string $recommendedDiskId,
    ) {}

    public function disk(string $id): ?DiskCapacityPlan
    {
        foreach ($this->disks as $disk) {
            if ($disk->id === $id) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     declared_size: int,
     *     can_start_new_upload: bool,
     *     can_replace_current_primary: bool,
     *     replaceable: array<string, mixed>|null,
     *     recommended_disk_id: string|null,
     *     blockers: list<array{code: string, message: string, disk: array{id: string, label: string|null}|null}>,
     *     disks: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'declared_size' => $this->declaredSize,
            'can_start_new_upload' => $this->canStartNewUpload,
            'can_replace_current_primary' => $this->canReplaceCurrentPrimary,
            'replaceable' => $this->replaceable?->toArray(),
            'recommended_disk_id' => $this->recommendedDiskId,
            'blockers' => array_map(
                fn (MovieConflictBlocker $blocker): array => $blocker->toArray(),
                $this->blockers,
            ),
            'disks' => array_map(
                fn (DiskCapacityPlan $disk): array => $disk->toArray(),
                $this->disks,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
