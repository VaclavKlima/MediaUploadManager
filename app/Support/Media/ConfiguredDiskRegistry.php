<?php

namespace App\Support\Media;

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaConfigurationException;

final class ConfiguredDiskRegistry
{
    private const BYTES_PER_GIB = 1_073_741_824;

    /**
     * @var list<ConfiguredMediaDisk>|null
     */
    private ?array $roots = null;

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
     * Existing Movie-only compatibility API.
     *
     * @return list<ConfiguredMediaDisk>
     */
    public function all(): array
    {
        return $this->forKind(MediaRootKind::Movies);
    }

    public function find(string $id): ?ConfiguredMediaDisk
    {
        return $this->findRoot($id, MediaRootKind::Movies);
    }

    /**
     * @return list<ConfiguredMediaDisk>
     */
    public function allRoots(): array
    {
        $this->ensureParsed();

        return $this->roots ?? [];
    }

    /**
     * @return list<ConfiguredMediaDisk>
     */
    public function forKind(MediaRootKind $kind): array
    {
        return array_values(array_filter(
            $this->allRoots(),
            fn (ConfiguredMediaDisk $root): bool => $root->kind === $kind,
        ));
    }

    public function findRoot(string $id, MediaRootKind $kind): ?ConfiguredMediaDisk
    {
        foreach ($this->forKind($kind) as $root) {
            if ($root->id === $id) {
                return $root;
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
        if ($this->roots !== null) {
            return;
        }

        $this->roots = $this->parse($this->configuration, $this->production);
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

        $roots = [];
        $seenIds = [];
        $seenLabels = [];

        foreach (array_values($rawDisks) as $rawDisk) {
            if (! is_array($rawDisk)) {
                $errors[] = 'Each media disk definition must be an array.';

                continue;
            }

            $id = $this->stringValue($rawDisk['id'] ?? null);
            $label = $this->stringValue($rawDisk['label'] ?? null);
            $legacyRoot = $this->normalizeOptionalRoot($rawDisk['path'] ?? null, $errors);
            $movieRoot = $this->normalizeOptionalRoot($rawDisk['movies_path'] ?? null, $errors);
            $seriesRoot = $this->normalizeOptionalRoot($rawDisk['series_path'] ?? null, $errors);
            $seriesDefaultCategory = $this->parseSeriesDefaultCategory(
                $rawDisk['series_default_category'] ?? null,
                $errors,
            );
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

            if ($legacyRoot !== null && $movieRoot !== null && ! $this->sameResolvedRoot($legacyRoot, $movieRoot)) {
                $errors[] = 'A legacy media disk path and explicit Movie path must resolve identically when both are configured.';
            }

            $resolvedMovieRoot = $movieRoot ?? $legacyRoot;

            if ($resolvedMovieRoot === null && $seriesRoot === null) {
                $errors[] = 'Every media disk must configure at least one Movie or Series root.';
            }

            if ($seriesDefaultCategory !== null && $seriesRoot === null) {
                $errors[] = 'A Series default category requires a configured Series root.';
            }

            if ($reserveBytes === null) {
                $errors[] = 'Every media disk reserve must be a nonnegative integer that fits in bytes.';
            }

            if ($resolvedMovieRoot !== null && $seriesRoot !== null) {
                $availableMovieRoot = $this->resolvedRoot($resolvedMovieRoot);
                $availableSeriesRoot = $this->resolvedRoot($seriesRoot);
                $movieDevice = $availableMovieRoot === null
                    ? null
                    : $this->filesystem->deviceId($availableMovieRoot);
                $seriesDevice = $availableSeriesRoot === null
                    ? null
                    : $this->filesystem->deviceId($availableSeriesRoot);

                if ($movieDevice !== null && $seriesDevice !== null && $movieDevice !== $seriesDevice) {
                    $errors[] = 'Movie and Series roots sharing a media disk ID must use the same filesystem.';
                }
            }

            if ($id === null || preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1
                || $label === null || $label === '' || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
                || $reserveBytes === null
            ) {
                continue;
            }

            if ($resolvedMovieRoot !== null) {
                $roots[] = new ConfiguredMediaDisk(
                    $id,
                    $label,
                    $resolvedMovieRoot,
                    $reserveBytes,
                    MediaRootKind::Movies,
                );
            }

            if ($seriesRoot !== null) {
                $roots[] = new ConfiguredMediaDisk(
                    $id,
                    $label,
                    $seriesRoot,
                    $reserveBytes,
                    MediaRootKind::Series,
                    $seriesDefaultCategory,
                );
            }
        }

        $this->validateDistinctRoots($roots, $errors);

        if ($errors !== []) {
            throw new MediaConfigurationException(array_values(array_unique($errors)));
        }

        $this->requireMountpoint = $requireMountpoint;

        return $roots;
    }

    /**
     * @param  list<ConfiguredMediaDisk>  $roots
     * @param  list<string>  $errors
     */
    private function validateDistinctRoots(array $roots, array &$errors): void
    {
        foreach ($roots as $index => $root) {
            $resolvedRoot = $this->resolvedRoot($root->root);

            foreach (array_slice($roots, 0, $index) as $otherRoot) {
                $resolvedOtherRoot = $this->resolvedRoot($otherRoot->root);

                if ($root->root === $otherRoot->root) {
                    $errors[] = 'Media roots must use unique paths across every disk and kind.';

                    continue;
                }

                if ($this->isNested($root->root, $otherRoot->root)) {
                    $errors[] = 'Media roots may not contain or be nested beneath another configured root.';
                }

                if ($resolvedRoot !== null && $resolvedOtherRoot !== null) {
                    if ($resolvedRoot === $resolvedOtherRoot) {
                        $errors[] = 'Media roots must resolve to unique paths across every disk and kind.';
                    } elseif ($this->isNested($resolvedRoot, $resolvedOtherRoot)) {
                        $errors[] = 'Resolved media roots may not contain or be nested beneath another configured root.';
                    }
                }
            }
        }
    }

    private function sameResolvedRoot(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        $resolvedFirst = $this->resolvedRoot($first);
        $resolvedSecond = $this->resolvedRoot($second);

        return $resolvedFirst !== null && $resolvedFirst === $resolvedSecond;
    }

    private function resolvedRoot(string $root): ?string
    {
        $resolvedRoot = $this->filesystem->realPath($root);

        return $resolvedRoot === null ? null : (rtrim($resolvedRoot, '/') ?: '/');
    }

    private function isNested(string $first, string $second): bool
    {
        return str_starts_with($first.'/', $second.'/')
            || str_starts_with($second.'/', $first.'/');
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

    /**
     * @param  list<string>  $errors
     */
    private function parseSeriesDefaultCategory(mixed $value, array &$errors): ?SeriesCategory
    {
        if ($value === null || $value === '') {
            return null;
        }

        $category = is_string($value) ? SeriesCategory::tryFrom(trim($value)) : null;

        if ($category === null) {
            $errors[] = 'Every Series default category must be tv or anime.';
        }

        return $category;
    }

    /**
     * @param  list<string>  $errors
     */
    private function normalizeOptionalRoot(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $root = $this->normalizeRoot($value);

        if ($root === null) {
            $errors[] = 'Every configured media root must be an absolute normalized POSIX path other than root.';
        }

        return $root;
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
