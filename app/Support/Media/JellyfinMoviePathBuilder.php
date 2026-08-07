<?php

namespace App\Support\Media;

use App\Models\MediaItem;
use InvalidArgumentException;
use Normalizer;

final class JellyfinMoviePathBuilder
{
    /** @var list<string> */
    public const SUPPORTED_EXTENSIONS = [
        'mkv',
        'mp4',
        'm4v',
        'avi',
        'mov',
        'ts',
        'm2ts',
        'webm',
    ];

    private const MAX_SEGMENT_BYTES = 255;

    public function build(MediaItem $mediaItem, string $sourceFilename): CanonicalMoviePath
    {
        $extension = $this->extensionFrom($sourceFilename);
        $title = $this->sanitizeTitle($mediaItem->title);
        $year = $mediaItem->release_year;

        if ($year === null || $year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('A canonical movie path requires a release year.');
        }

        if ($mediaItem->tmdb_id < 1) {
            throw new InvalidArgumentException('A canonical movie path requires a TMDB identity.');
        }

        $suffix = " ({$year}) [tmdbid-{$mediaItem->tmdb_id}]";
        $maximumStemBytes = self::MAX_SEGMENT_BYTES - strlen('.'.$extension);
        $title = $this->truncateToBytes($title, $maximumStemBytes - strlen($suffix));

        if ($title === '') {
            throw new InvalidArgumentException('A canonical movie path requires a nonempty safe title.');
        }

        $stem = $title.$suffix;
        $filename = $stem.'.'.$extension;

        return new CanonicalMoviePath(
            directory: $stem,
            filename: $filename,
            relativePath: $stem.'/'.$filename,
            extension: $extension,
        );
    }

    private function extensionFrom(string $sourceFilename): string
    {
        $filename = $this->normalize($sourceFilename);

        if ($filename === ''
            || strlen($filename) > self::MAX_SEGMENT_BYTES
            || $filename === '.'
            || $filename === '..'
            || preg_match('#[/\\\\\x00-\x1F\x7F]#u', $filename) === 1
        ) {
            throw new InvalidArgumentException('The source filename must be a safe basename.');
        }

        $separator = strrpos($filename, '.');

        if ($separator === false || trim(substr($filename, 0, $separator)) === '') {
            throw new InvalidArgumentException('The source filename must include a basename and extension.');
        }

        $extension = strtolower(substr($filename, $separator + 1));

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('The source file extension is not supported.');
        }

        return $extension;
    }

    private function sanitizeTitle(string $title): string
    {
        $title = $this->normalize($title);
        $title = preg_replace('/[<>:"\/\\\\|?*\x{0000}-\x{001F}\x{007F}]+/u', ' ', $title);

        if ($title === null) {
            throw new InvalidArgumentException('The movie title could not be sanitized.');
        }

        $title = preg_replace('/\s+/u', ' ', $title);

        if ($title === null) {
            throw new InvalidArgumentException('The movie title could not be sanitized.');
        }

        return rtrim(trim($title), ' .');
    }

    private function normalize(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        if (! is_string($normalized)) {
            throw new InvalidArgumentException('The value is not valid Unicode.');
        }

        return $normalized;
    }

    private function truncateToBytes(string $value, int $maximumBytes): string
    {
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException('The canonical movie identity exceeds the path segment limit.');
        }

        if (strlen($value) <= $maximumBytes) {
            return $value;
        }

        $low = 0;
        $high = grapheme_strlen($value);

        if ($high === false) {
            throw new InvalidArgumentException('The movie title is not valid Unicode.');
        }

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $candidate = grapheme_substr($value, 0, $middle);

            if ($candidate !== false && strlen($candidate) <= $maximumBytes) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        $truncated = grapheme_substr($value, 0, $low);

        if ($truncated === false) {
            throw new InvalidArgumentException('The movie title could not be truncated safely.');
        }

        return rtrim($truncated, ' .');
    }
}
