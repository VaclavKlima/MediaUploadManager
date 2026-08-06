<?php

namespace App\Support\Media;

use JsonException;

final class DiskMarker
{
    public const VERSION = 1;

    public static function encode(string $diskId): string
    {
        return json_encode(
            ['version' => self::VERSION, 'disk_id' => $diskId],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * @return array{version: int, disk_id: string}|null
     */
    public static function parse(string $contents): ?array
    {
        try {
            $marker = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($marker)
            || count($marker) !== 2
            || ! array_key_exists('version', $marker)
            || ! array_key_exists('disk_id', $marker)
            || $marker['version'] !== self::VERSION
            || ! is_string($marker['disk_id'])
            || preg_match('/^[a-z][a-z0-9_]*$/', $marker['disk_id']) !== 1
        ) {
            return null;
        }

        return ['version' => self::VERSION, 'disk_id' => $marker['disk_id']];
    }
}
