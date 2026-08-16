export type EpisodeHintKind =
    'season_episode' | 'cross_notation' | 'season_folder';

export type EpisodeHint = {
    seasonNumber: number;
    episodeNumber: number;
    identity: string;
    kind: EpisodeHintKind;
};

export type EpisodeHintSource = {
    sourceKey: string;
    filename: string;
    relativePath: string;
};

export type MatchedEpisodeHintSource = EpisodeHintSource & {
    hint: EpisodeHint | null;
};

export type SequentialAssignmentSource = {
    sourceKey: string;
    assignmentOrigin: 'auto' | 'manual' | 'bulk' | null;
};

export type SequentialEpisode = {
    id: number;
    episodeNumber: number;
};

export type ExistingEpisodeAssignment = {
    sourceKey: string;
    episodeId: number;
};

export type SequentialAssignment = {
    sourceKey: string;
    episodeId: number;
    episodeNumber: number;
};

export type SequentialAssignmentConflict = {
    sourceKey: string;
    episodeNumber: number | null;
    reason: 'manual_assignment' | 'episode_missing' | 'episode_in_use';
};

export type SequentialAssignmentPlan = {
    assignments: SequentialAssignment[];
    conflicts: SequentialAssignmentConflict[];
};

const naturalPathCollator = new Intl.Collator(undefined, {
    numeric: true,
    sensitivity: 'base',
});

const seasonEpisodePattern =
    /(?<![\p{L}\p{N}])S(\d{1,4})[._\-\s]*E(\d{1,4})(?!\d)/giu;
const crossNotationPattern =
    /(?<![\p{L}\p{N}])(\d{1,4})[._\-\s]*x[._\-\s]*(\d{1,4})(?!\d)/giu;
const explicitEpisodePattern =
    /(?<![\p{L}\p{N}])(?:Episode[._\-\s]*|E)(\d{1,4})(?!\d)/giu;

export function naturalRelativePathCompare(
    first: EpisodeHintSource,
    second: EpisodeHintSource,
): number {
    return naturalPathCollator.compare(
        normalizeRelativePath(first.relativePath),
        normalizeRelativePath(second.relativePath),
    );
}

export function matchEpisodeHints(
    sources: EpisodeHintSource[],
): MatchedEpisodeHintSource[] {
    return sources
        .map((source) => ({
            ...source,
            filename: source.filename.normalize('NFC'),
            relativePath: normalizeRelativePath(source.relativePath),
            hint: matchEpisodeHint(source),
        }))
        .sort(naturalRelativePathCompare);
}

export function matchEpisodeHint(
    source: EpisodeHintSource,
): EpisodeHint | null {
    const filename = source.filename.normalize('NFC');
    const relativePath = normalizeRelativePath(source.relativePath);
    const directHint = matchExactlyOne(filename, seasonEpisodePattern);

    if (directHint) {
        return episodeHint(directHint[0], directHint[1], 'season_episode');
    }

    const crossHint = matchExactlyOne(filename, crossNotationPattern);

    if (crossHint) {
        return episodeHint(crossHint[0], crossHint[1], 'cross_notation');
    }

    const segments = relativePath.split('/');
    const folderSegments = segments.slice(0, -1).reverse();
    const seasonNumber = folderSegments
        .map(seasonNumberFromFolder)
        .find((value) => value !== null);
    const explicitEpisode = matchExactlyOne(filename, explicitEpisodePattern);

    if (seasonNumber === undefined || !explicitEpisode) {
        return null;
    }

    return episodeHint(seasonNumber, explicitEpisode[0], 'season_folder');
}

export function duplicateEpisodeHintSourceKeys(
    sources: MatchedEpisodeHintSource[],
): Set<string> {
    const sourceKeysByIdentity = new Map<string, string[]>();

    sources.forEach((source) => {
        if (!source.hint) {
            return;
        }

        const sourceKeys = sourceKeysByIdentity.get(source.hint.identity) ?? [];
        sourceKeys.push(source.sourceKey);
        sourceKeysByIdentity.set(source.hint.identity, sourceKeys);
    });

    return new Set(
        [...sourceKeysByIdentity.values()]
            .filter((sourceKeys) => sourceKeys.length > 1)
            .flat(),
    );
}

export function planSequentialAssignments(
    sources: SequentialAssignmentSource[],
    episodes: SequentialEpisode[],
    startingEpisodeNumber: number,
    existingAssignments: ExistingEpisodeAssignment[],
): SequentialAssignmentPlan {
    const assignments: SequentialAssignment[] = [];
    const conflicts: SequentialAssignmentConflict[] = [];
    const sourceKeys = new Set(sources.map((source) => source.sourceKey));
    const occupiedEpisodeIds = new Set(
        existingAssignments
            .filter((assignment) => !sourceKeys.has(assignment.sourceKey))
            .map((assignment) => assignment.episodeId),
    );
    const episodeByNumber = new Map(
        episodes.map((episode) => [episode.episodeNumber, episode]),
    );
    let episodeNumber = startingEpisodeNumber;

    sources.forEach((source) => {
        if (source.assignmentOrigin === 'manual') {
            conflicts.push({
                sourceKey: source.sourceKey,
                episodeNumber: null,
                reason: 'manual_assignment',
            });

            return;
        }

        const episode = episodeByNumber.get(episodeNumber);

        if (!episode) {
            conflicts.push({
                sourceKey: source.sourceKey,
                episodeNumber,
                reason: 'episode_missing',
            });
        } else if (occupiedEpisodeIds.has(episode.id)) {
            conflicts.push({
                sourceKey: source.sourceKey,
                episodeNumber,
                reason: 'episode_in_use',
            });
        } else {
            assignments.push({
                sourceKey: source.sourceKey,
                episodeId: episode.id,
                episodeNumber,
            });
            occupiedEpisodeIds.add(episode.id);
        }

        episodeNumber += 1;
    });

    return { assignments, conflicts };
}

function normalizeRelativePath(relativePath: string): string {
    return relativePath.normalize('NFC').replaceAll('\\', '/');
}

function seasonNumberFromFolder(folder: string): number | null {
    const normalized = folder.normalize('NFC').trim();

    if (/^Specials$/iu.test(normalized)) {
        return 0;
    }

    const seasonMatch = normalized.match(/^Season[._\-\s]*(\d{1,4})$/iu);

    if (seasonMatch) {
        return Number(seasonMatch[1]);
    }

    const shortMatch = normalized.match(/^S(\d{1,4})$/iu);

    return shortMatch ? Number(shortMatch[1]) : null;
}

function matchExactlyOne(
    value: string,
    pattern: RegExp,
): [number, number] | null {
    const matches = [...value.matchAll(pattern)];

    if (matches.length !== 1) {
        return null;
    }

    const first = Number(matches[0][1]);
    const second = Number(matches[0][2] ?? matches[0][1]);

    if (
        !Number.isSafeInteger(first) ||
        !Number.isSafeInteger(second) ||
        first < 0 ||
        second < 1
    ) {
        return null;
    }

    return [first, second];
}

function episodeHint(
    seasonNumber: number,
    episodeNumber: number,
    kind: EpisodeHintKind,
): EpisodeHint | null {
    if (
        !Number.isSafeInteger(seasonNumber) ||
        !Number.isSafeInteger(episodeNumber) ||
        seasonNumber < 0 ||
        episodeNumber < 1
    ) {
        return null;
    }

    return {
        seasonNumber,
        episodeNumber,
        identity: `S${seasonNumber.toString().padStart(2, '0')}E${episodeNumber.toString().padStart(2, '0')}`,
        kind,
    };
}
