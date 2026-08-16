import assert from 'node:assert/strict';
import test from 'node:test';
import {
    duplicateEpisodeHintSourceKeys,
    matchEpisodeHints,
    planSequentialAssignments,
} from '../../resources/js/lib/seriesEpisodeMatcher.ts';

function source(relativePath: string) {
    return {
        sourceKey: relativePath,
        filename: relativePath.split('/').at(-1) ?? relativePath,
        relativePath,
    };
}

test('matches conservative episode identities and season folders', () => {
    const matches = matchEpisodeHints([
        source('Show/Show.S01E02.mkv'),
        source('Show/1x03 - Three.mp4'),
        source('Show/Season 2/E04 - Four.mkv'),
        source('Show/S03/Episode 05 - Five.mkv'),
        source('Show/Specials/E01 - Special.mkv'),
    ]);

    assert.deepEqual(matches.map((match) => match.hint?.identity).sort(), [
        'S00E01',
        'S01E02',
        'S01E03',
        'S02E04',
        'S03E05',
    ]);
});

test('normalizes Unicode and sorts relative paths naturally', () => {
    const matches = matchEpisodeHints([
        source('Cafe\u0301/Season 1/Episode 10.mkv'),
        source('Caf\u00e9/Season 1/Episode 2.mkv'),
    ]);

    assert.deepEqual(
        matches.map((match) => match.hint?.episodeNumber),
        [2, 10],
    );
    assert.equal(matches[0].relativePath, 'Caf\u00e9/Season 1/Episode 2.mkv');
});

test('does not guess title-only, absolute, or malformed identities', () => {
    const matches = matchEpisodeHints([
        source('Show/Blue.mkv'),
        source('Show/012.mkv'),
        source('Show/Show.S01E00.mkv'),
        source('Show/Show.1x0.mkv'),
        source('Show/Season 1/Finale.mkv'),
    ]);

    assert.ok(matches.every((match) => match.hint === null));
});

test('reports every duplicate automatic suggestion', () => {
    const matches = matchEpisodeHints([
        source('Show/Season 1/Copy A S01E02.mkv'),
        source('Show/Season 1/Copy B 1x02.mkv'),
        source('Show/Season 1/Show.S01E03.mkv'),
    ]);

    assert.deepEqual([...duplicateEpisodeHintSourceKeys(matches)].sort(), [
        'Show/Season 1/Copy A S01E02.mkv',
        'Show/Season 1/Copy B 1x02.mkv',
    ]);
});

test('plans sequential assignments without overwriting manual work', () => {
    const plan = planSequentialAssignments(
        [
            { sourceKey: 'a', assignmentOrigin: null },
            { sourceKey: 'b', assignmentOrigin: 'manual' },
            { sourceKey: 'c', assignmentOrigin: null },
            { sourceKey: 'd', assignmentOrigin: null },
        ],
        [
            { id: 101, episodeNumber: 1 },
            { id: 102, episodeNumber: 2 },
            { id: 104, episodeNumber: 4 },
        ],
        1,
        [{ sourceKey: 'outside', episodeId: 102 }],
    );

    assert.deepEqual(plan.assignments, [
        { sourceKey: 'a', episodeId: 101, episodeNumber: 1 },
    ]);
    assert.deepEqual(plan.conflicts, [
        {
            sourceKey: 'b',
            episodeNumber: null,
            reason: 'manual_assignment',
        },
        {
            sourceKey: 'c',
            episodeNumber: 2,
            reason: 'episode_in_use',
        },
        {
            sourceKey: 'd',
            episodeNumber: 3,
            reason: 'episode_missing',
        },
    ]);
});
