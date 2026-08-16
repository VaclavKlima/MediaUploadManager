import assert from 'node:assert/strict';
import test from 'node:test';
import {
    aggregateSeriesQueueProgress,
    matchSeriesRecoveryFiles,
    nextSeriesQueueIndex,
    normalizeRecoveryPath,
} from '../../resources/js/lib/seriesUploadQueue.ts';
import type { SeriesBatchItem } from '../../resources/js/types/series.ts';

function item(
    uploadUuid: string,
    sourceIdentity: string,
    status = 'pending',
): SeriesBatchItem {
    return {
        upload_uuid: uploadUuid,
        position: 1,
        source_identity: sourceIdentity,
        source_basename: sourceIdentity.split('/').at(-1) ?? sourceIdentity,
        last_modified_milliseconds: 1,
        expires_at: null,
        expired_at: null,
        episode: {
            id: 1,
            identity: 'S01E01',
            title: 'Pilot',
            season_number: 1,
            episode_number: 1,
        },
        destination: 'Show/Season 01/Pilot.mkv',
        status,
        declared_bytes: 10,
        confirmed_bytes: 0,
        failure: null,
        replacement: null,
        actions: {
            authorize: true,
            pause: false,
            retry: false,
            cancel: true,
        },
        finalized: null,
    };
}

test('matches recovery files by normalized path then unique basename', () => {
    const first = new File(['first'], 'Show.S01E01.mkv');
    const second = new File(['second'], 'Show.S01E02.mkv');
    const result = matchSeriesRecoveryFiles(
        [
            item('one', 'Show/Season 01/Show.S01E01.mkv'),
            item('two', 'Show/Season 01/Show.S01E02.mkv'),
        ],
        [second, first],
    );

    assert.deepEqual(
        result.matches.map((match) => match.uploadUuid),
        ['one', 'two'],
    );
    assert.equal(result.missing.length, 0);
    assert.equal(result.ambiguous.length, 0);
    assert.equal(normalizeRecoveryPath('Show\\Season 01\\Pilot.mkv'), 'Show/Season 01/Pilot.mkv');
});

test('rejects ambiguous basenames and ignores unrelated files', () => {
    const duplicateA = new File(['a'], 'Pilot.mkv');
    const duplicateB = new File(['b'], 'Pilot.mkv');
    const unrelated = new File(['c'], 'Notes.txt');
    const result = matchSeriesRecoveryFiles(
        [item('one', 'Show/Season 01/Pilot.mkv')],
        [duplicateA, duplicateB, unrelated],
    );

    assert.equal(result.ambiguous.length, 1);
    assert.equal(result.matches.length, 0);
    assert.equal(result.unrelated.length, 3);
});

test('selects stopped work first and aggregates resolved work separately from bytes', () => {
    assert.equal(
        nextSeriesQueueIndex([
            { status: 'completed' },
            { status: 'pending' },
            { status: 'failed' },
        ]),
        2,
    );
    assert.equal(
        nextSeriesQueueIndex([
            { status: 'completed' },
            { status: 'cancelled' },
        ]),
        null,
    );

    assert.deepEqual(
        aggregateSeriesQueueProgress([
            {
                status: 'completed',
                confirmedBytes: 10,
                batchItem: { declared_bytes: 10 },
            },
            {
                status: 'cancelled',
                confirmedBytes: 3,
                batchItem: { declared_bytes: 10 },
            },
        ]),
        {
            resolvedCount: 2,
            completedCount: 1,
            skippedCount: 1,
            transferredBytes: 13,
            declaredBytes: 20,
        },
    );
});
