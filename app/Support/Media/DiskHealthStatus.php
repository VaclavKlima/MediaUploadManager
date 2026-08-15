<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use JsonSerializable;

final readonly class DiskHealthStatus implements JsonSerializable
{
    /**
     * @param  list<DiskHealthReason>  $reasons
     */
    public function __construct(
        public string $id,
        public string $label,
        public MediaRootKind $kind,
        public bool $healthy,
        public bool $eligible,
        public ?int $totalBytes,
        public ?int $freeBytes,
        public int $safetyReserveBytes,
        public ?int $usableBytes,
        public array $reasons,
        public ?int $deviceId,
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
            ...$this->statusValues(),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     kind: string,
     *     health: 'healthy'|'unhealthy',
     *     eligible: bool,
     *     total_bytes: int|null,
     *     free_bytes: int|null,
     *     safety_reserve_bytes: int,
     *     usable_bytes: int|null,
     *     reasons: list<array{code: string, message: string}>
     * }
     */
    public function toRootArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind->value,
            ...$this->statusValues(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array{
     *     health: 'healthy'|'unhealthy',
     *     eligible: bool,
     *     total_bytes: int|null,
     *     free_bytes: int|null,
     *     safety_reserve_bytes: int,
     *     usable_bytes: int|null,
     *     reasons: list<array{code: string, message: string}>
     * }
     */
    private function statusValues(): array
    {
        return [
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
}
