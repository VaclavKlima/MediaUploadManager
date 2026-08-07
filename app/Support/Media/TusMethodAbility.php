<?php

namespace App\Support\Media;

final class TusMethodAbility
{
    public static function for(string $method): ?string
    {
        return match (strtoupper($method)) {
            'POST' => 'tus:create',
            'HEAD' => 'tus:read',
            'PATCH' => 'tus:write',
            'DELETE' => 'tus:terminate',
            default => null,
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return ['tus:create', 'tus:read', 'tus:write', 'tus:terminate'];
    }
}
