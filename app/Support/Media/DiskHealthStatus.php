<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class DiskHealthStatus implements JsonSerializable
{
    /**
     * @param  list<DiskHealthReason>  $reasons
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $healthy,
        public bool $eligible,
        public ?int $totalBytes,
        public ?int $freeBytes,
        public int $safetyReserveBytes,
        public ?int $usableBytes,
        public array $reasons,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     health: 'healthy'|'unhealthy',
     *     eligible: bool,
     *     total_bytes: int|null,
     *     free_bytes: int|null,
     *     safety_reserve_bytes: int,
     *     usable_bytes: int|null,
     *     reasons: list<array{code: string, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'health' => $this->healthy ? 'healthy' : 'unhealthy',
            'eligible' => $this->eligible,
            'total_bytes' => $this->totalBytes,
            'free_bytes' => $this->freeBytes,
            'safety_reserve_bytes' => $this->safetyReserveBytes,
            'usable_bytes' => $this->usableBytes,
            'reasons' => array_map(
                fn (DiskHealthReason $reason): array => [
                    'code' => $reason->value,
                    'message' => $reason->message(),
                ],
                $this->reasons,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
