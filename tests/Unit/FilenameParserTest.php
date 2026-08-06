<?php

use App\Support\Tmdb\FilenameParser;

it('extracts clean titles and plausible release years from filenames', function (string $filename, string $title, ?int $year) {
    $parsed = new FilenameParser()->parse($filename);

    expect($parsed->filename)->toBe(basename($filename))
        ->and($parsed->title)->toBe($title)
        ->and($parsed->year)->toBe($year);
})->with([
    'release tokens and group' => ['Movie.Title.2024.2160p.WEB-DL.DDP5.1.H.265-GROUP.mkv', 'Movie Title', 2024],
    'unicode title' => ['Amélie.2001.1080p.BluRay.x264-FRENCH.mkv', 'Amélie', 2001],
    'bracketed release data' => ['Dune (2021) [2160p] [HDR].mkv', 'Dune', 2021],
    'title containing an earlier year' => ['1917.2019.1080p.BluRay.x264-GROUP.mkv', '1917', 2019],
    'punctuation without release data' => ['Spider-Man Into the Spider-Verse.mkv', 'Spider-Man Into the Spider-Verse', null],
    'multiple years retain title year' => ['2001.A.Space.Odyssey.1968.REMASTERED.1080p.mkv', '2001 A Space Odyssey', 1968],
    'ambiguous ordinary name' => ['Heat.mkv', 'Heat', null],
    'path is reduced to file name' => ['/tmp/千と千尋の神隠し.2001.1080p.mkv', '千と千尋の神隠し', 2001],
    'czech release scene filename' => ['[TBS] Jak.vytrhnout.velrybě.stoličku.1977.CZ.dab.1080p.WEB-DL.x264.mkv', 'Jak vytrhnout velrybě stoličku', 1977],
    'lotr release strips edition and technical suffix' => ['The.Lord.of.the.Rings.The.Fellowship.of.the.Ring.2001.EXTENDED.1080p.BluRay.x265.10bit.6CH.MkvCage.ws.mkv', 'The Lord of the Rings The Fellowship of the Ring', 2001],
    'anime subtitle keeps legitimate hyphen' => ['Rurouni.Kenshin.-.Soukai.no.Namida-hen.2025.1080p.WEB-DL.JPN.Audio.mkv', 'Rurouni Kenshin - Soukai no Namida-hen', 2025],
    'standalone numeric movie title' => ['1917.mkv', '1917', null],
    'leading team and hash labels' => ['[EMBER][A1B2C3D4] Amélie.2001.1080p.mkv', 'Amélie', 2001],
    'bare leading hash' => ['A1B2C3D4.The.Matrix.1999.1080p.mkv', 'The Matrix', 1999],
    'english audio marker' => ['Parasite.2019.English.Dub.1080p.mkv', 'Parasite', 2019],
    'japanese bracketed audio marker' => ['千と千尋の神隠し.2001.[JPN].BluRay.mkv', '千と千尋の神隠し', 2001],
    'release domain' => ['The.Matrix.1999.MkvCage.ws.mkv', 'The Matrix', 1999],
    'edition without release year' => ['Blade.Runner.FINAL.CUT.1080p.BluRay.mkv', 'Blade Runner', null],
    'legitimate hyphenated title' => ['Kizumonogatari - Koyomi Vamp - Soukai no Namida-hen.mkv', 'Kizumonogatari - Koyomi Vamp - Soukai no Namida-hen', null],
]);

it('builds deduplicated internal variants without changing public metadata', function () {
    $parsed = new FilenameParser()->parse('Amélie: Le fabuleux destin.2001.1080p.mkv');

    expect($parsed->searchVariants)->toBe([
        'Amélie: Le fabuleux destin',
        'Amélie',
        'Amelie: Le fabuleux destin',
    ])->and($parsed->toArray())->toBe([
        'filename' => 'Amélie: Le fabuleux destin.2001.1080p.mkv',
        'title' => 'Amélie: Le fabuleux destin',
        'year' => 2001,
    ]);
});
