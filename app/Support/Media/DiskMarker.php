<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use JsonException;

final readonly class DiskMarker
{
    public const VERSION = 2;

    public const LEGACY_VERSION = 1;

    public function __construct(
        public int $version,
        public string $diskId,
        public MediaRootKind $kind,
    ) {}

    public static function encode(string $diskId, MediaRootKind $kind = MediaRootKind::Movies): string
    {
        return json_encode(
            ['version' => self::VERSION, 'disk_id' => $diskId, 'kind' => $kind->value],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    public static function encodeLegacy(string $diskId): string
    {
        return json_encode(
            ['version' => self::LEGACY_VERSION, 'disk_id' => $diskId],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    public static function parse(string $contents): ?self
    {
        try {
            $marker = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($marker) || ! is_int($marker['version'] ?? null)) {
            return null;
        }

        if ($marker['version'] === self::LEGACY_VERSION
            && count($marker) === 2
            && is_string($marker['disk_id'] ?? null)
            && preg_match('/^[a-z][a-z0-9_]*$/', $marker['disk_id']) === 1
        ) {
            return new self(self::LEGACY_VERSION, $marker['disk_id'], MediaRootKind::Movies);
        }

        $kind = is_string($marker['kind'] ?? null)
            ? MediaRootKind::tryFrom($marker['kind'])
            : null;

        if ($marker['version'] !== self::VERSION
            || count($marker) !== 3
            || ! is_string($marker['disk_id'] ?? null)
            || preg_match('/^[a-z][a-z0-9_]*$/', $marker['disk_id']) !== 1
            || $kind === null
        ) {
            return null;
        }

        return new self(self::VERSION, $marker['disk_id'], $kind);
    }

    public function isLegacy(): bool
    {
        return $this->version === self::LEGACY_VERSION;
    }
}
