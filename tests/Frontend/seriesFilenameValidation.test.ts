import assert from 'node:assert/strict';
import test from 'node:test';
import { matchEpisodeHints } from '../../resources/js/lib/seriesEpisodeMatcher.ts';
import { isUnsupportedMultipartFilename } from '../../resources/js/lib/seriesFilenameValidation.ts';

const gargantiaFilenames = [
    'Gargantia On The Verdurous Planet - S00E17 - The Ocean Routes Stretch Into The Distance, Pt. 1.mkv',
    'Gargantia On The Verdurous Planet - S00E18 - The Ocean Routes Stretch Into The Distance, Pt. 2.mkv',
];

for (const filename of [
    ...gargantiaFilenames,
    'Show.S01E02.1080p.mkv',
    'Show - S01E02 - A Long Journey, Part 1.mkv',
    'Show - s01e03 - A Long Journey, pT.2.MKV',
    'Show - S01E04 - A Long Journey, PART. 2.mp4',
    'Show - S01E05 - Cafe\u0301, Pt. 2.mkv',
]) {
    test(`allows a single episode with a title part: ${filename}`, () => {
        assert.equal(isUnsupportedMultipartFilename(filename), false);
    });
}

for (const filename of [
    'Show.S01E01.Part.2.mkv',
    'Show.S01E01.pt2.mkv',
    'Show - S01E01 - A Long Journey Part 2.mkv',
    'Show.S01E01.A Long Journey, Pt. 2.mkv',
    'Show - S01E01 - , Pt. 2.mkv',
    'Show - S01E01 - ... , Pt. 2.mkv',
    'Show - S01E01 - A Long Journey, Pt. 0.mkv',
    'Show.Part.2 - S01E01 - A Long Journey, Pt. 1.mkv',
    'Show - S01E01 - Part 2 of A Long Journey, Pt. 1.mkv',
    'Show - S01E01 - A Long Journey, Pt. 1.Part.2.mkv',
    'Show - S01E01 - A Long Journey, Pt. 1, Pt. 2.mkv',
    'Show - S01E01 - Part 2, A Long Journey, Pt. 1.mkv',
    'Show,Part.2 - S01E01 - A Long Journey, Pt. 1.mkv',
    'Show - S01E01E02 - A Long Journey, Pt. 1.mkv',
    'Show - S01E01-E02 - A Long Journey, Pt. 1.mkv',
    'Show - S01E01.S01E02 - A Long Journey, Pt. 1.mkv',
    'Show - S01E00 - A Long Journey, Pt. 1.mkv',
    'Show - A Long Journey, Pt. 1.mkv',
]) {
    test(`retains the multipart block: ${filename}`, () => {
        assert.equal(isUnsupportedMultipartFilename(filename), true);
    });
}

test('retains distinct special identities when selected together or individually', () => {
    for (const filenames of [
        gargantiaFilenames,
        [gargantiaFilenames[0]],
        [gargantiaFilenames[1]],
    ]) {
        const sources = filenames.map((filename) => ({
            filename,
            sourceKey: `Show/Specials/${filename}`,
            relativePath: `Show/Specials/${filename}`,
        }));
        const accepted = sources.filter(
            (source) => !isUnsupportedMultipartFilename(source.filename),
        );

        assert.equal(accepted.length, filenames.length);
        assert.deepEqual(
            matchEpisodeHints(accepted).map((source) => source.hint?.identity),
            filenames.map((filename) =>
                filename === gargantiaFilenames[0] ? 'S00E17' : 'S00E18',
            ),
        );
    }
});
