<?php

namespace App\Support\Media;

use JsonSerializable;

final readonly class MovieConflictBlocker implements JsonSerializable
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $diskId = null,
        public ?string $diskLabel = null,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     disk: array{id: string, label: string|null}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'disk' => $this->diskId === null ? null : [
                'id' => $this->diskId,
                'label' => $this->diskLabel,
            ],
        ];
    }

    /** @return array{code: string, message: string} */
    public function toReasonArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     disk: array{id: string, label: string|null}|null
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
