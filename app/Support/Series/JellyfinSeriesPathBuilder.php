<?php

namespace App\Support\Series;

use App\Models\SeriesEpisode;
use App\Support\Media\JellyfinMoviePathBuilder;
use InvalidArgumentException;
use Normalizer;

final class JellyfinSeriesPathBuilder
{
    private const MAX_SEGMENT_BYTES = 255;

    public function build(SeriesEpisode $episode, string $sourceFilename): CanonicalSeriesPath
    {
        if (! $episode->relationLoaded('season') || ! $episode->season->relationLoaded('series')) {
            $episode->loadMissing('season.series');
        }
        $season = $episode->season;
        $series = $season->series;
        $extension = strtolower((string) pathinfo($sourceFilename, PATHINFO_EXTENSION));

        if (! in_array($extension, JellyfinMoviePathBuilder::SUPPORTED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('The source file extension is not supported.');
        }

        if ($series->first_air_year === null || $series->tmdb_id < 1) {
            throw new InvalidArgumentException('A canonical Series path requires a TMDB identity and first-air year.');
        }

        $seriesName = $this->sanitize($series->name);
        $seriesSuffix = " ({$series->first_air_year}) [tmdbid-{$series->tmdb_id}]";
        $seriesDirectory = $this->truncate($seriesName, self::MAX_SEGMENT_BYTES - strlen($seriesSuffix)).$seriesSuffix;
        $identity = sprintf('S%02dE%02d', $season->season_number, $episode->episode_number);
        $episodePrefix = $this->sanitize($series->name).' '.$identity.' - ';
        $episodeName = $this->sanitize($episode->displayName());
        $episodeStem = $this->truncate($episodePrefix.$episodeName, self::MAX_SEGMENT_BYTES - strlen('.'.$extension));
        $seasonDirectory = sprintf('Season %02d', $season->season_number);
        $filename = $episodeStem.'.'.$extension;
        $relativePath = implode('/', [$seriesDirectory, $seasonDirectory, $episodeStem, $filename]);

        return new CanonicalSeriesPath($seriesDirectory, $seasonDirectory, $episodeStem, $filename, $relativePath, $extension);
    }

    private function sanitize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_C);

        if (! is_string($value)) {
            throw new InvalidArgumentException('The Series title is not valid Unicode.');
        }

        $value = preg_replace('/[<>:"\/\\\\|?*\x{0000}-\x{001F}\x{007F}]+/u', ' ', $value);
        $value = is_string($value) ? preg_replace('/\s+/u', ' ', $value) : null;
        $value = is_string($value) ? rtrim(trim($value), ' .') : '';

        if ($value === '') {
            throw new InvalidArgumentException('A canonical Series path requires nonempty safe titles.');
        }

        return $value;
    }

    private function truncate(string $value, int $maximumBytes): string
    {
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException('The canonical Series identity exceeds the path segment limit.');
        }

        while (strlen($value) > $maximumBytes) {
            $value = (string) grapheme_substr($value, 0, -1);
        }

        return rtrim($value, ' .');
    }
}
