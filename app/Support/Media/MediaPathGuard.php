<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Exceptions\MediaPathException;

final readonly class MediaPathGuard
{
    public function __construct(private MediaFilesystem $filesystem) {}

    public function resolveRoot(string $configuredRoot): string
    {
        if (! $this->filesystem->pathExists($configuredRoot)) {
            throw new MediaPathException('root_missing');
        }

        if (! $this->filesystem->isDirectory($configuredRoot)) {
            throw new MediaPathException('unsafe_root');
        }

        $this->rejectSymbolicLinksInAbsolutePath($configuredRoot);
        $resolvedRoot = $this->filesystem->realPath($configuredRoot);

        if ($resolvedRoot === null || $resolvedRoot === '/') {
            throw new MediaPathException('unsafe_root');
        }

        return rtrim($resolvedRoot, '/');
    }

    public function resolveChild(string $configuredRoot, string $relativePath): string
    {
        $this->validateRelativePath($relativePath);
        $resolvedRoot = $this->resolveRoot($configuredRoot);
        $candidate = $resolvedRoot;

        foreach (explode('/', $relativePath) as $segment) {
            $candidate .= '/'.$segment;

            if (! $this->filesystem->pathExists($candidate)) {
                continue;
            }

            if ($this->filesystem->isSymbolicLink($candidate)) {
                throw new MediaPathException('unsafe_child');
            }

            $resolvedCandidate = $this->filesystem->realPath($candidate);

            if ($resolvedCandidate === null || ! $this->isWithinRoot($resolvedRoot, $resolvedCandidate)) {
                throw new MediaPathException('unsafe_child');
            }
        }

        if (! $this->isWithinRoot($resolvedRoot, $candidate)) {
            throw new MediaPathException('unsafe_child');
        }

        return $candidate;
    }

    private function rejectSymbolicLinksInAbsolutePath(string $path): void
    {
        $currentPath = '';

        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $currentPath .= '/'.$segment;

            if ($this->filesystem->isSymbolicLink($currentPath)) {
                throw new MediaPathException('unsafe_root');
            }
        }
    }

    private function validateRelativePath(string $relativePath): void
    {
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1
        ) {
            throw new MediaPathException('unsafe_child');
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new MediaPathException('unsafe_child');
            }
        }
    }

    private function isWithinRoot(string $resolvedRoot, string $candidate): bool
    {
        return $candidate === $resolvedRoot || str_starts_with($candidate, $resolvedRoot.'/');
    }
}
