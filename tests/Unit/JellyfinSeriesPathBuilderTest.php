<?php

use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Support\Series\JellyfinSeriesPathBuilder;

function seriesPathEpisode(int $seasonNumber = 1, int $episodeNumber = 2, string $episodeName = 'The Beginning'): SeriesEpisode
{
    $series = new Series(['tmdb_id' => 123, 'name' => 'Café 東京', 'first_air_year' => 2026]);
    $season = new SeriesSeason(['season_number' => $seasonNumber]);
    $season->setRelation('series', $series);
    $episode = new SeriesEpisode(['episode_number' => $episodeNumber, 'name' => $episodeName]);
    $episode->setRelation('season', $season);

    return $episode;
}

it('builds canonical regular and Specials paths', function (int $season, int $episode, string $seasonDirectory, string $identity) {
    $path = (new JellyfinSeriesPathBuilder)->build(seriesPathEpisode($season, $episode), 'source.MKV');

    expect($path->seriesDirectory)->toBe('Café 東京 (2026) [tmdbid-123]')
        ->and($path->seasonDirectory)->toBe($seasonDirectory)
        ->and($path->episodeDirectory)->toBe("Café 東京 {$identity} - The Beginning")
        ->and($path->relativePath)->toBe("Café 東京 (2026) [tmdbid-123]/{$seasonDirectory}/Café 東京 {$identity} - The Beginning/Café 東京 {$identity} - The Beginning.mkv");
})->with([
    [1, 2, 'Season 01', 'S01E02'],
    [0, 105, 'Season 00', 'S00E105'],
]);

it('sanitizes unsafe characters and truncates long Unicode segments', function () {
    $path = (new JellyfinSeriesPathBuilder)->build(
        seriesPathEpisode(123, 456, str_repeat('Family 👨‍👩‍👧‍👦 / finale ', 40)),
        'source.webm',
    );

    expect(strlen($path->episodeDirectory))->toBeLessThanOrEqual(255)
        ->and(strlen($path->filename))->toBeLessThanOrEqual(255)
        ->and($path->relativePath)->not->toMatch('/[<>:"\\\\|?*\x00-\x1F\x7F]/');
});
