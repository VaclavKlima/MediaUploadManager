<?php

namespace App\Support\Media;

use App\ValueObjects\RelativeMediaPath;
use InvalidArgumentException;
use JsonSerializable;

final readonly class CanonicalMoviePath implements JsonSerializable
{
    public function __construct(
        public string $directory,
        public string $filename,
        public string $relativePath,
        public string $extension,
    ) {
        if ($relativePath !== $directory.'/'.$filename) {
            throw new InvalidArgumentException('The canonical movie path components do not match.');
        }

        if (strlen($directory) > 255 || strlen($filename) > 255) {
            throw new InvalidArgumentException('A canonical movie path segment exceeds the filesystem limit.');
        }

        new RelativeMediaPath($relativePath);
    }

    /**
     * @return array{directory: string, filename: string, relative_path: string, extension: string}
     */
    public function toArray(): array
    {
        return [
            'directory' => $this->directory,
            'filename' => $this->filename,
            'relative_path' => $this->relativePath,
            'extension' => $this->extension,
        ];
    }

    /** @return array{directory: string, filename: string, relative_path: string, extension: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
