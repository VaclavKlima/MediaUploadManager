<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MediaFilesystem;
use Throwable;

class NativeMediaFilesystem implements MediaFilesystem
{
    public function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isSymbolicLink(string $path): bool
    {
        return is_link($path);
    }

    public function isRegularFile(string $path): bool
    {
        return ! is_link($path) && is_file($path);
    }

    public function isDirectoryEmpty(string $path): bool
    {
        if (! is_dir($path) || is_link($path)) {
            return false;
        }

        $entries = @scandir($path);

        return is_array($entries) && count($entries) === 2;
    }

    public function realPath(string $path): ?string
    {
        $resolvedPath = realpath($path);

        return $resolvedPath === false ? null : $resolvedPath;
    }

    public function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function createDirectory(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0750);
    }

    public function removeDirectoryIfEmpty(string $path): bool
    {
        return ! file_exists($path) || (! is_link($path) && @rmdir($path));
    }

    public function readFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function writeFileExclusively(string $path, string $contents): bool
    {
        $handle = @fopen($path, 'x+b');

        if ($handle === false) {
            return false;
        }

        $completed = false;

        try {
            $remaining = $contents;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if ($written === false || $written === 0) {
                    return false;
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                return false;
            }

            $completed = true;

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            fclose($handle);

            if (! $completed) {
                @unlink($path);
            }
        }
    }

    public function fileSize(string $path): ?int
    {
        $size = @filesize($path);

        return is_int($size) ? $size : null;
    }

    public function deviceId(string $path): ?int
    {
        $metadata = @lstat($path);
        $device = is_array($metadata) ? $metadata['dev'] : null;

        return is_int($device) ? $device : null;
    }

    public function inodeId(string $path): ?int
    {
        $metadata = @lstat($path);
        $inode = is_array($metadata) ? $metadata['ino'] : null;

        return is_int($inode) ? $inode : null;
    }

    public function createHardLinkExclusively(string $source, string $target): bool
    {
        if ($this->pathExists($target)) {
            return false;
        }

        return @link($source, $target);
    }

    public function replaceFileAtomically(string $source, string $target): bool
    {
        return ! is_link($source)
            && is_file($source)
            && ! is_link($target)
            && is_file($target)
            && @rename($source, $target);
    }

    public function sameInode(string $first, string $second): bool
    {
        if ($this->isSymbolicLink($first) || $this->isSymbolicLink($second)) {
            return false;
        }

        $firstMetadata = @lstat($first);
        $secondMetadata = @lstat($second);

        return is_array($firstMetadata)
            && is_array($secondMetadata)
            && $firstMetadata['dev'] === $secondMetadata['dev']
            && $firstMetadata['ino'] === $secondMetadata['ino'];
    }

    public function deleteFile(string $path): bool
    {
        return ! $this->pathExists($path) || (! is_dir($path) && @unlink($path));
    }

    public function capacity(string $path): ?array
    {
        $totalBytes = @disk_total_space($path);
        $freeBytes = @disk_free_space($path);
        $normalizedTotalBytes = $this->normalizeCapacity($totalBytes);
        $normalizedFreeBytes = $this->normalizeCapacity($freeBytes);

        if ($normalizedTotalBytes === null || $normalizedFreeBytes === null) {
            return null;
        }

        return ['total' => $normalizedTotalBytes, 'free' => $normalizedFreeBytes];
    }

    public function probe(string $directory): bool
    {
        try {
            $identifier = bin2hex(random_bytes(16));
        } catch (Throwable) {
            return false;
        }

        $temporaryPath = $directory.'/.health-'.$identifier.'.tmp';
        $renamedPath = $directory.'/.health-'.$identifier.'.ready';
        $handle = null;

        try {
            $handle = @fopen($temporaryPath, 'x+b');

            if ($handle === false) {
                return false;
            }

            $payload = random_bytes(32);

            if (fwrite($handle, $payload) !== strlen($payload)) {
                return false;
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                return false;
            }

            fclose($handle);
            $handle = null;

            return @rename($temporaryPath, $renamedPath) && @unlink($renamedPath);
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }

            if (file_exists($renamedPath)) {
                @unlink($renamedPath);
            }
        }
    }

    private function normalizeCapacity(float|false $bytes): ?int
    {
        if ($bytes === false || ! is_finite($bytes) || $bytes < 0 || $bytes > PHP_INT_MAX) {
            return null;
        }

        return (int) floor($bytes);
    }
}
