<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class MovieDiskTargetStatus implements JsonSerializable
{
    /**
     * @param  'clear'|'replaceable'|'conflict'|'unavailable'  $status
     * @param  list<MovieConflictBlocker>  $reasons
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public array $reasons,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     status: 'clear'|'replaceable'|'conflict'|'unavailable',
     *     reasons: list<array{code: string, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'reasons' => array_map(
                fn (MovieConflictBlocker $reason): array => $reason->toReasonArray(),
                $this->reasons,
            ),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     status: 'clear'|'replaceable'|'conflict'|'unavailable',
     *     reasons: list<array{code: string, message: string}>
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
