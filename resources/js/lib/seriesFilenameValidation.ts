const seasonEpisodePattern =
    /(?<![\p{L}\p{N}])S(\d{1,4})[._\-\s]*E(\d{1,4})(?!\d)/giu;
const episodeTitlePartPattern =
    /((?<![\p{L}\p{N}])S\d{1,4}[._\-\s]*E\d{1,4}(?!\d)\s+-\s+.*[\p{L}\p{N}].*),\s*(?:part|pt)\.?\s*[1-9]\d*(\.[^.]+)$/iu;
const multipartPattern =
    /(?:^|[.,_\-\s])(?:part|pt)[._\-\s]*\d+(?:[.,_\-\s]|$)/iu;

export function isUnsupportedMultipartFilename(filename: string): boolean {
    const normalized = filename.normalize('NFC');
    const identities = [...normalized.matchAll(seasonEpisodePattern)];
    const filenameWithoutTitlePart =
        identities.length === 1 && Number(identities[0][2]) > 0
            ? normalized.replace(episodeTitlePartPattern, '$1$2')
            : normalized;

    return multipartPattern.test(filenameWithoutTitlePart);
}
