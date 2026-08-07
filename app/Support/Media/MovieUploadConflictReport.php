<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class MovieUploadConflictReport implements JsonSerializable
{
    /**
     * @param  list<MovieConflictBlocker>  $blockers
     * @param  list<MovieDiskTargetStatus>  $disks
     */
    public function __construct(
        public bool $canStartNewUpload,
        public bool $canReplaceCurrentPrimary,
        public ?ReplaceableMediaFile $replaceable,
        public array $blockers,
        public array $disks,
    ) {}

    /**
     * @return array{
     *     can_start_new_upload: bool,
     *     can_replace_current_primary: bool,
     *     replaceable: array<string, mixed>|null,
     *     blockers: list<array{code: string, message: string, disk: array{id: string, label: string|null}|null}>,
     *     disks: list<array{id: string, label: string, status: 'clear'|'replaceable'|'conflict'|'unavailable', reasons: list<array{code: string, message: string}>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'can_start_new_upload' => $this->canStartNewUpload,
            'can_replace_current_primary' => $this->canReplaceCurrentPrimary,
            'replaceable' => $this->replaceable?->toArray(),
            'blockers' => array_map(
                fn (MovieConflictBlocker $blocker): array => $blocker->toArray(),
                $this->blockers,
            ),
            'disks' => array_map(
                fn (MovieDiskTargetStatus $disk): array => $disk->toArray(),
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
