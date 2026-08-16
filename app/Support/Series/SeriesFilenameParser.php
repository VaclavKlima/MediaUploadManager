<?php

namespace App\Support\Series;

use App\Support\Media\JellyfinMoviePathBuilder;
use Normalizer;

final class SeriesFilenameParser
{
    public function parse(string $filename): ParsedEpisodeFilename
    {
        $normalized = Normalizer::normalize($filename, Normalizer::FORM_C);

        if (! is_string($normalized)
            || $normalized === ''
            || strlen($normalized) > 255
            || preg_match('#[/\\\\\x00-\x1F\x7F]#u', $normalized) === 1
        ) {
            return new ParsedEpisodeFilename($filename, null, null, 'unsafe_filename');
        }

        $extension = strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));

        if (! in_array($extension, JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)) {
            return new ParsedEpisodeFilename($normalized, null, null, 'unsupported_video');
        }

        if (preg_match('/(?:^|[._\-\s])(extras?|bonus|featurettes?|sample)(?:[._\-\s]|$)/iu', $normalized) === 1) {
            return new ParsedEpisodeFilename($normalized, null, null, 'known_extra');
        }

        preg_match_all('/(?<![A-Z0-9])S(\d{1,4})[._\-\s]*E(\d{1,4})(?!\d)/iu', $normalized, $matches, PREG_SET_ORDER);

        if (count($matches) !== 1) {
            return new ParsedEpisodeFilename($normalized, null, null, count($matches) > 1 ? 'multi_episode' : 'episode_identity_missing');
        }

        if (preg_match('/E\d{1,4}[._\-\s]*(?:E|[-+]\s*E?)\d{1,4}/iu', $normalized) === 1
            || preg_match('/(?:^|[._\-\s])(?:part|pt)[._\-\s]*\d+(?:[._\-\s]|$)/iu', $normalized) === 1
        ) {
            return new ParsedEpisodeFilename($normalized, null, null, 'multipart_or_multiple_version');
        }

        $season = (int) $matches[0][1];
        $episode = (int) $matches[0][2];

        if ($episode < 1) {
            return new ParsedEpisodeFilename($normalized, null, null, 'episode_identity_invalid');
        }

        return new ParsedEpisodeFilename($normalized, $season, $episode);
    }
}
