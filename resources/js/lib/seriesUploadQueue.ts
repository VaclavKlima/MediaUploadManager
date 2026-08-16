import type { SeriesBatchItem, SeriesUploadStatus } from '@/types/series';

export type RecoveryFileMatch = {
    uploadUuid: string;
    sourceIdentity: string;
    file: File;
};

export type RecoveryMatchResult = {
    matches: RecoveryFileMatch[];
    missing: SeriesBatchItem[];
    ambiguous: SeriesBatchItem[];
    unrelated: File[];
};

const transferStatuses = new Set<SeriesUploadStatus>([
    'pending',
    'uploading',
    'paused',
]);

export function normalizeRecoveryPath(path: string): string {
    return path.normalize('NFC').replaceAll('\\', '/').replace(/^\.\//u, '');
}

export function matchSeriesRecoveryFiles(
    items: SeriesBatchItem[],
    files: File[],
): RecoveryMatchResult {
    const needed = items.filter((item) =>
        transferStatuses.has(item.status as SeriesUploadStatus),
    );
    const candidates = files.map((file) => ({
        file,
        path: normalizeRecoveryPath(file.webkitRelativePath || file.name),
    }));
    const used = new Set<File>();
    const matches: RecoveryFileMatch[] = [];
    const missing: SeriesBatchItem[] = [];
    const ambiguous: SeriesBatchItem[] = [];

    for (const item of needed) {
        const source = normalizeRecoveryPath(item.source_identity);
        const available = candidates.filter(({ file }) => !used.has(file));
        let found = available.filter(({ path }) => path === source);

        if (found.length === 0) {
            found = available.filter(
                ({ path }) =>
                    path.endsWith(`/${source}`) || source.endsWith(`/${path}`),
            );
        }

        if (found.length === 0) {
            const basename = source.split('/').at(-1);
            found = available.filter(
                ({ path }) => path.split('/').at(-1) === basename,
            );
        }

        if (found.length === 0) {
            missing.push(item);
        } else if (found.length > 1) {
            ambiguous.push(item);
        } else {
            used.add(found[0].file);
            matches.push({
                uploadUuid: item.upload_uuid,
                sourceIdentity: item.source_identity,
                file: found[0].file,
            });
        }
    }

    return {
        matches,
        missing,
        ambiguous,
        unrelated: files.filter((file) => !used.has(file)),
    };
}

export function nextSeriesQueueIndex(
    items: Array<{ status: SeriesUploadStatus }>,
): number | null {
    const stopped = items.findIndex(
        (item) => item.status === 'failed' || item.status === 'expired',
    );

    if (stopped >= 0) {
        return stopped;
    }

    const next = items.findIndex(
        (item) => item.status !== 'completed' && item.status !== 'cancelled',
    );

    return next < 0 ? null : next;
}

export function aggregateSeriesQueueProgress(
    items: Array<{
        status: SeriesUploadStatus;
        confirmedBytes: number;
        batchItem: { declared_bytes: number };
    }>,
): {
    resolvedCount: number;
    completedCount: number;
    skippedCount: number;
    transferredBytes: number;
    declaredBytes: number;
} {
    return items.reduce(
        (aggregate, item) => {
            aggregate.declaredBytes += item.batchItem.declared_bytes;
            aggregate.transferredBytes += item.confirmedBytes;

            if (item.status === 'completed') {
                aggregate.completedCount += 1;
                aggregate.resolvedCount += 1;
            } else if (item.status === 'cancelled') {
                aggregate.skippedCount += 1;
                aggregate.resolvedCount += 1;
            }

            return aggregate;
        },
        {
            resolvedCount: 0,
            completedCount: 0,
            skippedCount: 0,
            transferredBytes: 0,
            declaredBytes: 0,
        },
    );
}
