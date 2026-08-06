<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaConfigurationException;

final class ConfiguredDiskRegistry
{
    private const BYTES_PER_GIB = 1_073_741_824;

    /**
     * @var list<ConfiguredMediaDisk>
     */
    private ?array $disks = null;

    private ?bool $requireMountpoint = null;

    /**
     * @param  array<mixed>  $configuration
     */
    public function __construct(
        private readonly array $configuration,
        private readonly MediaFilesystem $filesystem,
        private readonly bool $production,
    ) {}

    /**
     * @return list<ConfiguredMediaDisk>
     */
    public function all(): array
    {
        $this->ensureParsed();

        /** @var list<ConfiguredMediaDisk> $disks */
        $disks = $this->disks;

        return $disks;
    }

    public function find(string $id): ?ConfiguredMediaDisk
    {
        foreach ($this->all() as $disk) {
            if ($disk->id === $id) {
                return $disk;
            }
        }

        return null;
    }

    public function requiresMountpoint(): bool
    {
        $this->ensureParsed();

        return $this->requireMountpoint ?? false;
    }

    private function ensureParsed(): void
    {
        if ($this->disks !== null) {
            return;
        }

        $this->disks = $this->parse($this->configuration, $this->production);
    }

    /**
     * @param  array<mixed>  $configuration
     * @return list<ConfiguredMediaDisk>
     */
    private function parse(array $configuration, bool $production): array
    {
        $errors = [];
        $rawDisks = $configuration['disks'] ?? null;
        $defaultReserve = $this->parseReserve($configuration['default_reserve_gib'] ?? null);
        $requireMountpoint = $this->parseBoolean($configuration['require_mountpoint'] ?? null);

        if (! is_array($rawDisks)) {
            $errors[] = 'The media disk list must be an array.';
            $rawDisks = [];
        }

        if ($defaultReserve === null) {
            $errors[] = 'The default media disk reserve must be a nonnegative integer that fits in bytes.';
        }

        if ($requireMountpoint === null) {
            $errors[] = 'The mountpoint requirement must be a boolean value.';
            $requireMountpoint = false;
        }

        if ($production && $rawDisks === []) {
            $errors[] = 'At least one media disk is required in production.';
        }

        $disks = [];
        $seenIds = [];
        $seenLabels = [];
        $seenPaths = [];
        $seenResolvedPaths = [];

        foreach (array_values($rawDisks) as $rawDisk) {
            if (! is_array($rawDisk)) {
                $errors[] = 'Each media disk definition must be an array.';

                continue;
            }

            $id = $this->stringValue($rawDisk['id'] ?? null);
            $label = $this->stringValue($rawDisk['label'] ?? null);
            $root = $this->normalizeRoot($rawDisk['path'] ?? null);
            $reserveValue = $rawDisk['reserve_gib'] ?? null;
            $reserveBytes = $reserveValue === null ? $defaultReserve : $this->parseReserve($reserveValue);

            if ($id === null || preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
                $errors[] = 'Every media disk ID must use lowercase letters, numbers, or underscores and start with a letter.';
            } elseif (isset($seenIds[$id])) {
                $errors[] = 'Media disk IDs must be unique.';
            } else {
                $seenIds[$id] = true;
            }

            if ($label === null || $label === '' || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
                $errors[] = 'Every media disk label must be nonempty and contain no control characters.';
            } elseif (isset($seenLabels[$label])) {
                $errors[] = 'Media disk labels must be unique.';
            } else {
                $seenLabels[$label] = true;
            }

            if ($root === null) {
                $errors[] = 'Every media disk path must be an absolute normalized POSIX path other than root.';
            } elseif (isset($seenPaths[$root])) {
                $errors[] = 'Media disk paths must be unique.';
            } else {
                $seenPaths[$root] = true;
                $resolvedRoot = $this->filesystem->realPath($root);

                if ($resolvedRoot !== null) {
                    $resolvedRoot = rtrim($resolvedRoot, '/') ?: '/';

                    if (isset($seenResolvedPaths[$resolvedRoot])) {
                        $errors[] = 'Media disk paths must resolve to unique roots.';
                    } else {
                        $seenResolvedPaths[$resolvedRoot] = true;
                    }
                }
            }

            if ($reserveBytes === null) {
                $errors[] = 'Every media disk reserve must be a nonnegative integer that fits in bytes.';
            }

            if ($id !== null && preg_match('/^[a-z][a-z0-9_]*$/', $id) === 1
                && $label !== null && $label !== '' && preg_match('/[\x00-\x1F\x7F]/', $label) !== 1
                && $root !== null
                && $reserveBytes !== null
            ) {
                $disks[] = new ConfiguredMediaDisk($id, $label, $root, $reserveBytes);
            }
        }

        if ($errors !== []) {
            throw new MediaConfigurationException(array_values(array_unique($errors)));
        }

        $this->requireMountpoint = $requireMountpoint;

        return $disks;
    }

    private function parseReserve(mixed $value): ?int
    {
        if (is_int($value)) {
            $reserveGib = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', trim($value)) === 1) {
            $reserveGib = (int) trim($value);
        } else {
            return null;
        }

        if ($reserveGib < 0 || $reserveGib > intdiv(PHP_INT_MAX, self::BYTES_PER_GIB)) {
            return null;
        }

        return $reserveGib * self::BYTES_PER_GIB;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', '(true)' => true,
            '0', 'false', '(false)' => false,
            default => null,
        };
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? trim($value) : null;
    }

    private function normalizeRoot(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = trim($value);

        if ($path === ''
            || ! str_starts_with($path, '/')
            || $path === '/'
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return null;
        }

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return rtrim($path, '/');
    }
}
