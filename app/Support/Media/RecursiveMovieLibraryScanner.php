<?php

namespace App\Support\Media;

use Illuminate\Support\Str;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

final class RecursiveMovieLibraryScanner
{
    public function __construct(private readonly MediaPathGuard $pathGuard) {}

    /**
     * @return list<array{relative_path: string, source_folder: string, source_filename: string, size_bytes: int, device_id: int, inode_id: int}>
     */
    public function scan(ConfiguredMediaDisk $disk): array
    {
        $root = $this->pathGuard->resolveRoot($disk->root);

        try {
            $directory = new RecursiveDirectoryIterator(
                $root,
                RecursiveDirectoryIterator::SKIP_DOTS,
            );
        } catch (UnexpectedValueException) {
            return [];
        }

        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function (SplFileInfo $entry): bool {
                if ($entry->isLink()) {
                    return false;
                }

                return ! ($entry->isDir() && $entry->getFilename() === '.media-upload-manager');
            },
        );
        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        $files = [];

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->isLink() || ! $entry->isFile()) {
                continue;
            }

            $extension = strtolower($entry->getExtension());

            if (! in_array($extension, JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $relativePath = substr($entry->getPathname(), strlen($root) + 1);

            if ($relativePath === '' || Str::length($relativePath) > 1024) {
                continue;
            }

            $guardedPath = $this->pathGuard->resolveChild($disk->root, $relativePath);
            $metadata = @lstat($guardedPath);

            if (! is_array($metadata)) {
                continue;
            }

            $folder = dirname($relativePath);
            $files[] = [
                'relative_path' => $relativePath,
                'source_folder' => $folder === '.' ? '' : $folder,
                'source_filename' => basename($relativePath),
                'size_bytes' => $metadata['size'],
                'device_id' => $metadata['dev'],
                'inode_id' => $metadata['ino'],
            ];
        }

        usort($files, fn (array $left, array $right): int => $left['relative_path'] <=> $right['relative_path']);

        return $files;
    }
}
