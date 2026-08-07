<?php

namespace App\Support\Tmdb;

use App\Support\Tmdb\Data\ParsedFilename;
use Illuminate\Support\Str;
use Normalizer;

final class FilenameParser
{
    private const RELEASE_MARKER_PATTERN = '/(?<![\pL\pN])(?:2160p|1080[pi]|720p|576p|480p|4k|uhd|hdr10\+?|hdr|dolby[ ._-]?vision|blu[ ._-]?ray|bdrip|brrip|web[ ._-]?(?:dl|rip)|webrip|hdtv|remux|x26[45]|h[ ._-]?26[45]|hevc|avc|av1|10[ ._-]?bit|8[ ._-]?bit|\d(?:\.\d)?[ ._-]?ch|aac(?:[ ._-]?(?:2\.0|5\.1|7\.1))?|ac3|eac3|ddp?(?:[ ._-]?(?:2\.0|5\.1|7\.1))?|dts(?:[ ._-]?hd)?|truehd|atmos|proper|repack|extended|unrated|limited|internal|imax|remastered|director(?:\x{2019}|\x{2018}|\x{0027})?s[ ._-]?cut|theatrical[ ._-]?cut|final[ ._-]?cut|ultimate[ ._-]?edition|collector(?:\x{2019}|\x{2018}|\x{0027})?s[ ._-]?edition)(?![\pL\pN])/iu';

    private const LANGUAGE_MARKER_PATTERN = '/(?<![\pL\pN])(?:[\[(](?:cz|cze|cs|en|eng|jp|jpn)[\])]|(?:cz|cze|cs|en|eng|jp|jpn|czech|english|japanese)[ ._-]+(?:dab|dub(?:bed)?|audio)|(?:dual|multi)[ ._-]+audio)(?![\pL\pN])/iu';

    private const DOMAIN_PATTERN = '/(?<![\pL\pN])(?:www\.)?[\pL\pN][\pL\pN-]{1,62}\.(?:com|net|org|io|tv|ws|cz|sk|eu|co|me)(?![\pL\pN])/iu';

    private const HASH_PATTERN = '/(?<![\pL\pN])[a-f0-9]{8,64}(?![\pL\pN])/iu';

    public function parse(string $filename): ParsedFilename
    {
        $normalizedFilename = Normalizer::normalize($filename, Normalizer::FORM_C);
        $filename = is_string($normalizedFilename) ? $normalizedFilename : $filename;
        $basename = Str::afterLast(str_replace('\\', '/', $filename), '/');
        $stem = preg_replace('/\.(?:mkv|mp4|m4v|avi|mov|wmv|webm|m2ts|ts|mpg|mpeg)\z/iu', '', $basename) ?? $basename;
        $stem = $this->removeLeadingReleaseLabels($stem);
        $year = $this->extractLastPlausibleYear($stem);
        $releaseBoundary = $this->releaseBoundary($stem);

        if ($year !== null && $releaseBoundary !== null && $releaseBoundary > $year['offset']) {
            $stem = substr($stem, 0, $year['offset']);
        } elseif ($releaseBoundary !== null) {
            $stem = substr($stem, 0, $releaseBoundary);
        } elseif ($year !== null) {
            $stem = substr_replace($stem, ' ', $year['offset'], strlen($year['value']));
        }

        $stem = preg_replace('/[._]+/u', ' ', $stem) ?? $stem;
        $stem = preg_replace('/\s*([:])\s*/u', '$1 ', $stem) ?? $stem;
        $stem = preg_replace('/\s+([\-–—])\s+/u', ' $1 ', $stem) ?? $stem;
        $title = Str::of($stem)->squish()->trim(" \t\n\r\0\x0B-–—:[]{}()")->toString();

        if ($title === '') {
            $title = Str::of(pathinfo($basename, PATHINFO_FILENAME))->replace(['.', '_'], ' ')->squish()->toString();
        }

        return new ParsedFilename(
            $basename,
            $title,
            $year === null ? null : (int) $year['value'],
            $this->searchVariants($title),
        );
    }

    /** @return array{value: string, offset: int}|null */
    private function extractLastPlausibleYear(string $filename): ?array
    {
        preg_match_all('/(?<!\d)(18[8-9]\d|19\d{2}|20\d{2}|21[0-2]\d)(?!\d)/u', $filename, $matches, PREG_OFFSET_CAPTURE);

        foreach (array_reverse($matches[1]) as $match) {
            $titleBeforeYear = trim(substr($filename, 0, $match[1]), " \t\n\r\0\x0B._-–—()[]{}");

            if ($titleBeforeYear !== '') {
                return ['value' => $match[0], 'offset' => $match[1]];
            }
        }

        return null;
    }

    private function removeLeadingReleaseLabels(string $stem): string
    {
        $withoutLabels = preg_replace('/\A(?:\s*(?:\[[^\]]+\]|\{[^}]+\})[ ._-]*)+/u', '', $stem) ?? $stem;

        return preg_replace('/\A\s*[a-f0-9]{8,64}[ ._-]+/iu', '', $withoutLabels) ?? $withoutLabels;
    }

    private function releaseBoundary(string $stem): ?int
    {
        $offsets = [];

        foreach ([self::RELEASE_MARKER_PATTERN, self::LANGUAGE_MARKER_PATTERN, self::DOMAIN_PATTERN, self::HASH_PATTERN] as $pattern) {
            if (preg_match($pattern, $stem, $match, PREG_OFFSET_CAPTURE) === 1) {
                $offsets[] = $match[0][1];
            }
        }

        if ($offsets === []) {
            return null;
        }

        $boundary = min($offsets);
        $titleBeforeBoundary = trim(substr($stem, 0, $boundary), " \t\n\r\0\x0B._-–—()[]{}");

        return $titleBeforeBoundary === '' ? null : $boundary;
    }

    /** @return list<string> */
    private function searchVariants(string $title): array
    {
        $variants = [$title];
        $shortened = preg_split('/\s+[\-–—]\s+|:/u', $title, 2)[0] ?? $title;
        $shortened = Str::squish($shortened);

        if ($shortened !== '' && $this->isDistinctVariant($shortened, $variants)) {
            $variants[] = $shortened;
        }

        $transliterated = Str::squish(Str::transliterate($title));

        if ($transliterated !== '' && $this->isDistinctVariant($transliterated, $variants)) {
            $variants[] = $transliterated;
        }

        return $variants;
    }

    /** @param list<string> $variants */
    private function isDistinctVariant(string $candidate, array $variants): bool
    {
        $normalizedCandidate = Str::lower($candidate);

        return ! in_array($normalizedCandidate, array_map(Str::lower(...), $variants), true);
    }
}
