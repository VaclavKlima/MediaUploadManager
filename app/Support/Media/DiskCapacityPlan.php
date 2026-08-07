<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class DiskCapacityPlan implements JsonSerializable
{
    /**
     * @param  'clear'|'replaceable'|'conflict'|'unavailable'  $status
     * @param  'atomic_same_path_swap'|'finalize_then_delete'|null  $replacementMethod
     * @param  list<array{code: string, message: string}>  $reasons
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public bool $healthy,
        public ?int $totalBytes,
        public ?int $freeBytes,
        public int $safetyReserveBytes,
        public ?int $usableBytes,
        public int $activeReservedBytes,
        public ?int $projectedUsableBytes,
        public bool $eligible,
        public ?string $replacementMethod,
        public array $reasons,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     status: 'clear'|'replaceable'|'conflict'|'unavailable',
     *     health: 'healthy'|'unhealthy',
     *     total_bytes: int|null,
     *     free_bytes: int|null,
     *     safety_reserve_bytes: int,
     *     usable_bytes: int|null,
     *     active_reserved_bytes: int,
     *     projected_usable_bytes: int|null,
     *     eligible: bool,
     *     replacement_method: 'atomic_same_path_swap'|'finalize_then_delete'|null,
     *     reasons: list<array{code: string, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'health' => $this->healthy ? 'healthy' : 'unhealthy',
            'total_bytes' => $this->totalBytes,
            'free_bytes' => $this->freeBytes,
            'safety_reserve_bytes' => $this->safetyReserveBytes,
            'usable_bytes' => $this->usableBytes,
            'active_reserved_bytes' => $this->activeReservedBytes,
            'projected_usable_bytes' => $this->projectedUsableBytes,
            'eligible' => $this->eligible,
            'replacement_method' => $this->replacementMethod,
            'reasons' => $this->reasons,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
