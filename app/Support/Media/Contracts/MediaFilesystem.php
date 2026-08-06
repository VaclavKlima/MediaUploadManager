<?php

namespace App\Support\Media\Contracts;

interface MediaFilesystem
{
    public function pathExists(string $path): bool;

    public function isDirectory(string $path): bool;

    public function isSymbolicLink(string $path): bool;

    public function realPath(string $path): ?string;

    public function isReadable(string $path): bool;

    public function isWritable(string $path): bool;

    public function createDirectory(string $path): bool;

    public function readFile(string $path): ?string;

    public function writeFileExclusively(string $path, string $contents): bool;

    /**
     * @return array{total: int, free: int}|null
     */
    public function capacity(string $path): ?array;

    public function probe(string $directory): bool;
}
