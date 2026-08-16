import { useHttp } from '@inertiajs/vue3';
import type { Upload as TusUpload } from 'tus-js-client';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    index as indexSeriesBatchesAction,
    preview as previewSeriesBatchAction,
    recovery as recoverSeriesBatchAction,
    show as showSeriesBatchAction,
    store as storeSeriesBatchAction,
} from '@/actions/App/Http/Controllers/Series/SeriesBatchController';
import {
    confirm as confirmSeriesAction,
    hydrateSeason as hydrateSeasonAction,
    search as searchSeriesAction,
    show as showSeriesAction,
    suggestions as suggestSeriesAction,
} from '@/actions/App/Http/Controllers/Series/SeriesLookupController';
import UploadAuthorizationController from '@/actions/App/Http/Controllers/UploadAuthorizationController';
import UploadController from '@/actions/App/Http/Controllers/UploadController';
import UploadPauseController from '@/actions/App/Http/Controllers/UploadPauseController';
import {
    duplicateEpisodeHintSourceKeys,
    matchEpisodeHints,
    planSequentialAssignments,
} from '@/lib/seriesEpisodeMatcher';
import type {
    EpisodeHint,
    EpisodeHintSource,
    SequentialAssignmentPlan,
} from '@/lib/seriesEpisodeMatcher';
import {
    aggregateSeriesQueueProgress,
    matchSeriesRecoveryFiles,
    nextSeriesQueueIndex,
} from '@/lib/seriesUploadQueue';
import {
    createUploadTransport,
    fingerprintUploadFile,
} from '@/lib/uploadTransport';
import type {
    ConfirmedSeries,
    ConfirmedSeriesEpisode,
    ParsedSeriesSource,
    PreviewSeriesBatchRequest,
    SeriesConfirmationResponse,
    SeriesBatch,
    SeriesBatchAdmissionRequest,
    SeriesBatchItem,
    SeriesBatchPreview,
    SeriesBatchPreviewResponse,
    SeriesBatchResponse,
    ResumableSeriesBatchesResponse,
    SeriesRecoveryRequest,
    SeriesDetailsResponse,
    SeriesLookupResponse,
    SeriesSearchResult,
    SeriesUploadAuthorizationResponse,
    SeriesUploadSession,
    SeriesUploadSessionResponse,
} from '@/types/series';
import type {
    UploadConnectionState,
    UploadFingerprint,
} from '@/types/upload-transport';

export const supportedEpisodeAccept =
    '.mkv,.mp4,.m4v,.avi,.mov,.ts,.m2ts,.webm';

export type SeriesUploadWizardStep = 1 | 2 | 3 | 4 | 5 | 6;

export type AcceptedEpisodeFile = EpisodeHintSource & {
    file: File;
    size: number;
    hint: EpisodeHint | null;
};

export type SeriesSourceIssue = {
    id: string;
    filename: string | null;
    message: string;
    blocking: boolean;
};

export type EpisodeAssignmentOrigin = 'auto' | 'manual' | 'bulk' | null;

export type EpisodeReviewValidationStatus =
    | 'auto'
    | 'edited'
    | 'needs_assignment'
    | 'conflict'
    | 'replacement_required';

export type EpisodeReviewMapping = {
    sourceKey: string;
    hint: EpisodeHint | null;
    selectedSeasonNumber: number | null;
    selectedEpisodeNumber: number | null;
    seriesEpisodeId: number | null;
    assignmentOrigin: EpisodeAssignmentOrigin;
    validationStatus: EpisodeReviewValidationStatus;
    replacesMediaFileId: number | null;
    replacementConfirmed: boolean;
};

export type EpisodeReviewRow = EpisodeReviewMapping & {
    source: AcceptedEpisodeFile;
    selectedEpisode: ConfirmedSeriesEpisode | null;
};

export type EpisodeReviewGroup = {
    key: string;
    label: string;
    rows: EpisodeReviewRow[];
    attentionCount: number;
};

export type ReviewedEpisodeMapping = {
    sourceKey: string;
    seriesEpisodeId: number;
    replacesMediaFileId: number | null;
    replacementConfirmed: boolean;
};

type SeriesSourceAnalysis = {
    acceptedFiles: AcceptedEpisodeFile[];
    issues: SeriesSourceIssue[];
};

type SeasonHydrationState = 'idle' | 'loading' | 'error';

export type SeriesQueueItem = {
    batchItem: SeriesBatchItem;
    sourceKey: string;
    file: File | null;
    fingerprint: UploadFingerprint | null;
    status: SeriesUploadSession['status'];
    confirmedBytes: number;
    failure: SeriesUploadSession['failure'];
};

const supportedVideoExtensions = new Set([
    'mkv',
    'mp4',
    'm4v',
    'avi',
    'mov',
    'ts',
    'm2ts',
    'webm',
]);

const knownExtraPattern =
    /(?:^|[._\-\s])(extras?|bonus|featurettes?|sample)(?:[._\-\s]|$)/iu;
const seasonEpisodePattern =
    /(?<![\p{L}\p{N}])S\d{1,4}[._\-\s]*E\d{1,4}(?!\d)/giu;
const crossNotationPattern =
    /(?<![\p{L}\p{N}])\d{1,4}[._\-\s]*x[._\-\s]*\d{1,4}(?!\d)/giu;
const joinedEpisodePattern = /E\d{1,4}[._\-\s]*(?:E|[-+]\s*E?)\d{1,4}/iu;
const joinedCrossNotationPattern =
    /\d{1,4}x\d{1,4}[._\-\s]*(?:x|[-+]\s*)\d{1,4}/iu;
const multipartPattern =
    /(?:^|[._\-\s])(?:part|pt)[._\-\s]*\d+(?:[._\-\s]|$)/iu;

function extensionFor(filename: string): string {
    const extension = filename.match(/\.([^.]+)$/u)?.[1];

    return extension?.toLocaleLowerCase() ?? '';
}

function browserRelativePath(file: File): string {
    return (file.webkitRelativePath || file.name)
        .normalize('NFC')
        .replaceAll('\\', '/');
}

export function analyzeSeriesSourceFiles(files: File[]): SeriesSourceAnalysis {
    const sources: Array<EpisodeHintSource & { file: File; size: number }> = [];
    const issues: SeriesSourceIssue[] = [];

    files.forEach((file, index) => {
        const filename = file.name.normalize('NFC');
        const relativePath = browserRelativePath(file);
        const issueId = `${index}-${filename}`;
        const extension = extensionFor(filename);

        if (!supportedVideoExtensions.has(extension)) {
            issues.push({
                id: issueId,
                filename,
                message: extension
                    ? `Not included because .${extension} is not a supported video format.`
                    : 'Not included because the file has no supported video extension.',
                blocking: false,
            });

            return;
        }

        if (knownExtraPattern.test(filename)) {
            issues.push({
                id: issueId,
                filename,
                message:
                    'Not included because it looks like a known extra, bonus, featurette, or sample.',
                blocking: false,
            });

            return;
        }

        const directIdentityCount = [
            ...filename.matchAll(seasonEpisodePattern),
            ...filename.matchAll(crossNotationPattern),
        ].length;

        if (
            directIdentityCount > 1 ||
            joinedEpisodePattern.test(filename) ||
            joinedCrossNotationPattern.test(filename)
        ) {
            issues.push({
                id: issueId,
                filename,
                message:
                    'Contains more than one episode identity. Multi-episode videos are not supported yet.',
                blocking: true,
            });

            return;
        }

        if (multipartPattern.test(filename)) {
            issues.push({
                id: issueId,
                filename,
                message:
                    'Looks like a multipart video, which is not supported yet.',
                blocking: true,
            });

            return;
        }

        sources.push({
            file,
            filename,
            relativePath,
            sourceKey: relativePath,
            size: file.size,
        });

        if (file.size === 0) {
            issues.push({
                id: `${issueId}-empty`,
                filename,
                message:
                    'This video is empty (0 bytes). Choose a complete episode file.',
                blocking: true,
            });
        }
    });

    const sourceCounts = new Map<string, number>();

    sources.forEach((source) => {
        sourceCounts.set(
            source.sourceKey,
            (sourceCounts.get(source.sourceKey) ?? 0) + 1,
        );
    });
    sourceCounts.forEach((count, sourceKey) => {
        if (count > 1) {
            issues.push({
                id: `duplicate-source-${sourceKey}`,
                filename: sourceKey,
                message:
                    'This source path appears more than once. Choose a selection with unique video sources.',
                blocking: true,
            });
        }
    });

    if (sources.length > 1000) {
        issues.push({
            id: 'episode-limit',
            filename: null,
            message: `A selection can contain at most 1,000 videos; this selection has ${sources.length}.`,
            blocking: true,
        });
    }

    const sourceByKey = new Map(
        sources.map((source) => [source.sourceKey, source]),
    );
    const acceptedFiles = matchEpisodeHints(sources).map((matched) => {
        const source = sourceByKey.get(matched.sourceKey);

        if (!source) {
            throw new Error('A locally matched episode source disappeared.');
        }

        return { ...source, hint: matched.hint };
    });

    return { acceptedFiles, issues };
}

export function useSeriesUploadWizard(fingerprintWindowBytes: number) {
    const currentStep = ref<SeriesUploadWizardStep>(1);
    const selectedFiles = ref<File[]>([]);
    const searchInput = ref('');
    const results = ref<SeriesSearchResult[]>([]);
    const parsedSource = ref<ParsedSeriesSource | null>(null);
    const selectedSeries = ref<SeriesSearchResult | null>(null);
    const confirmedSeries = ref<ConfirmedSeries | null>(null);
    const category = ref<'tv' | 'anime'>('tv');
    const lookupError = ref('');
    const lookupCompleted = ref(false);
    const hasReturnedToSource = ref(false);
    const reviewMappings = ref<EpisodeReviewMapping[]>([]);
    const reviewedMappings = ref<ReviewedEpisodeMapping[] | null>(null);
    const seasonHydrationStates = ref<
        Partial<Record<number, SeasonHydrationState>>
    >({});
    const seasonHydrationErrors = ref<Partial<Record<number, string>>>({});
    const statusMessage = ref(
        'Choose an episode, season, or complete show folder.',
    );
    const storagePreview = ref<SeriesBatchPreview | null>(null);
    const storageError = ref('');
    const selectedDiskId = ref('');
    const isFingerprinting = ref(false);
    const fingerprintProgress = ref({ completed: 0, total: 0, filename: '' });
    const admittedBatch = ref<SeriesBatch | null>(null);
    const queueItems = ref<SeriesQueueItem[]>([]);
    const activeQueueIndex = ref<number | null>(null);
    const connectionState = ref<UploadConnectionState>('ready');
    const speedBytesPerSecond = ref(0);
    const etaSeconds = ref<number | null>(null);
    const uploadError = ref('');
    const admissionStarted = ref(false);
    const resumableBatches = ref<SeriesBatch[]>([]);
    const recoveryError = ref('');
    const isRecovering = ref(false);

    let lookupRequestId = 0;
    let confirmationRequestId = 0;
    let sourceRevision = 0;
    let returnStepAfterSource: 2 | 3 | 4 = 2;
    let lastLookupKind: 'suggestion' | 'search' = 'suggestion';
    let confirmedReviewKey: string | null = null;
    let storageRequestId = 0;
    let admissionRequestId = 0;
    let storageContextKey: string | null = null;
    let idempotencyKey: string | null = null;
    let idempotencyContextKey: string | null = null;
    let autoAdmissionAttemptedContext: string | null = null;
    let activeTusUpload: TusUpload | null = null;
    let activeAuthorization: SeriesUploadAuthorizationResponse['data'] | null =
        null;
    let authorizationPromise: Promise<void> | null = null;
    let processingPollTimer: number | null = null;
    let lastProgressAt = 0;
    let lastProgressBytes = 0;
    let lastProgressAnnouncementAt = 0;
    let queueGeneration = 0;
    let continuingSameShow = false;
    const fingerprintCache = new Map<string, UploadFingerprint>();

    const rowSelectionRevisions = new Map<string, number>();
    const pendingSeasonHydrations = new Map<
        number,
        {
            request: ReturnType<typeof createSeasonHydrationRequest>;
            promise: Promise<SeriesConfirmationResponse>;
        }
    >();

    const textLookup = useHttp<
        { query: string; year: string },
        SeriesLookupResponse
    >({ query: '', year: '' });
    const filenameLookup = useHttp<
        { source_name: string },
        SeriesLookupResponse
    >({ source_name: '' });
    const detailsLookup = useHttp<Record<string, never>, SeriesDetailsResponse>(
        {},
    );
    const confirmation = useHttp<
        { tmdb_id: number; category: 'tv' | 'anime'; season_numbers: number[] },
        SeriesConfirmationResponse
    >({ tmdb_id: 0, category: 'tv', season_numbers: [] });
    const previewRequest = useHttp<
        PreviewSeriesBatchRequest,
        SeriesBatchPreviewResponse
    >({ items: [] });
    const admissionRequest = useHttp<
        SeriesBatchAdmissionRequest,
        SeriesBatchResponse
    >({ idempotency_key: '', disk_id: '', items: [] });
    const authorizationRequest = useHttp<
        UploadFingerprint,
        SeriesUploadAuthorizationResponse
    >({
        filename: '',
        declared_size: 0,
        last_modified_milliseconds: null,
        fingerprint_first_sha256: '',
        fingerprint_last_sha256: '',
    });
    const statusRequest = useHttp<
        Record<string, never>,
        SeriesUploadSessionResponse
    >({});
    const pauseRequest = useHttp<
        Record<string, never>,
        SeriesUploadSessionResponse
    >({});
    const retryRequest = useHttp<
        Record<string, never>,
        SeriesUploadSessionResponse
    >({});
    const cancelRequest = useHttp<
        Record<string, never>,
        SeriesUploadSessionResponse
    >({});
    const resumableRequest = useHttp<
        Record<string, never>,
        ResumableSeriesBatchesResponse
    >({});
    const recoveryRequest = useHttp<SeriesRecoveryRequest, SeriesBatchResponse>(
        { items: [] },
    );
    const batchRequest = useHttp<Record<string, never>, SeriesBatchResponse>(
        {},
    );

    const analysis = computed(() =>
        analyzeSeriesSourceFiles(selectedFiles.value),
    );
    const acceptedFiles = computed(() => analysis.value.acceptedFiles);
    const issues = computed(() => analysis.value.issues);
    const blockingIssues = computed(() =>
        issues.value.filter((issue) => issue.blocking),
    );
    const excludedIssues = computed(() =>
        issues.value.filter((issue) => !issue.blocking),
    );
    const seasonNumbers = computed(() =>
        Array.from(
            new Set(
                acceptedFiles.value.flatMap((episode) =>
                    episode.hint ? [episode.hint.seasonNumber] : [],
                ),
            ),
        ).sort((first, second) => first - second),
    );
    const seasonCount = computed(() => seasonNumbers.value.length);
    const canContinue = computed(
        () =>
            acceptedFiles.value.length > 0 && blockingIssues.value.length === 0,
    );
    const sourceName = computed(() =>
        suggestionSourceName(acceptedFiles.value),
    );
    const isLookingUp = computed(
        () =>
            textLookup.processing ||
            filenameLookup.processing ||
            detailsLookup.processing,
    );
    const isConfirming = computed(() => confirmation.processing);
    const canKeepCurrentVideos = computed(
        () =>
            currentStep.value === 1 &&
            hasReturnedToSource.value &&
            canContinue.value,
    );
    const reviewRows = computed<EpisodeReviewRow[]>(() => {
        const sourceByKey = new Map(
            acceptedFiles.value.map((source) => [source.sourceKey, source]),
        );

        return reviewMappings.value.flatMap((mapping) => {
            const source = sourceByKey.get(mapping.sourceKey);

            if (!source) {
                return [];
            }

            return [
                {
                    ...mapping,
                    source,
                    selectedEpisode: episodeForMapping(mapping),
                },
            ];
        });
    });
    const reviewGroups = computed<EpisodeReviewGroup[]>(() => {
        const grouped = new Map<string, EpisodeReviewRow[]>();

        reviewRows.value.forEach((row) => {
            const group = reviewGroupFor(row.source);
            const rows = grouped.get(group.key) ?? [];
            rows.push(row);
            grouped.set(group.key, rows);
        });

        return [...grouped.entries()].map(([key, rows]) => ({
            key,
            label: reviewGroupLabel(key, rows[0]),
            rows,
            attentionCount: rows.filter(
                (row) => !isReadyStatus(row.validationStatus),
            ).length,
        }));
    });
    const reviewCounts = computed(() => ({
        mapped: reviewRows.value.filter((row) =>
            isReadyStatus(row.validationStatus),
        ).length,
        attention: reviewRows.value.filter(
            (row) => !isReadyStatus(row.validationStatus),
        ).length,
        replacements: reviewRows.value.filter(
            (row) => row.selectedEpisode?.has_current_primary,
        ).length,
    }));
    const isReviewReady = computed(
        () =>
            reviewRows.value.length > 0 &&
            reviewRows.value.every((row) =>
                isReadyStatus(row.validationStatus),
            ) &&
            !Object.values(seasonHydrationStates.value).includes('loading'),
    );
    const reviewConfirmed = computed(() => reviewedMappings.value !== null);
    const isStorageLoading = computed(() => previewRequest.processing);
    const isAdmitting = computed(
        () => isFingerprinting.value || admissionRequest.processing,
    );
    const activeQueueItem = computed(() =>
        activeQueueIndex.value === null
            ? null
            : (queueItems.value[activeQueueIndex.value] ?? null),
    );
    const queueProgress = computed(() =>
        aggregateSeriesQueueProgress(queueItems.value),
    );
    const overallConfirmedBytes = computed(
        () => queueProgress.value.transferredBytes,
    );
    const resolvedCount = computed(() => queueProgress.value.resolvedCount);
    const completedCount = computed(() => queueProgress.value.completedCount);
    const skippedCount = computed(() => queueProgress.value.skippedCount);

    function selectSources(files: File[]): void {
        if (admissionStarted.value) {
            return;
        }

        cancelLookups();
        cancelConfirmation();
        cancelSeasonHydrations();
        sourceRevision += 1;
        selectedFiles.value = [...files];
        results.value = [];
        parsedSource.value = null;

        if (!continuingSameShow) {
            selectedSeries.value = null;
            confirmedSeries.value = null;
            category.value = 'tv';
        }

        lookupError.value = '';
        lookupCompleted.value = false;
        hasReturnedToSource.value = false;
        reviewMappings.value = [];
        reviewedMappings.value = null;
        confirmedReviewKey = null;
        rowSelectionRevisions.clear();
        resetStorageState();

        if (files.length === 0) {
            statusMessage.value = 'No files were selected.';

            return;
        }

        const videoCount = acceptedFiles.value.length;
        const blockingCount = blockingIssues.value.length;
        const excludedCount = excludedIssues.value.length;
        const hintedCount = acceptedFiles.value.filter(
            (episode) => episode.hint !== null,
        ).length;

        if (canContinue.value) {
            if (continuingSameShow && selectedSeries.value) {
                continuingSameShow = false;
                currentStep.value = 2;
                statusMessage.value =
                    'Refreshing this Show for the selected seasons.';
                void confirmSeries();

                return;
            }

            currentStep.value = 2;
            statusMessage.value = `${videoCount} video ${videoCount === 1 ? 'file is' : 'files are'} ready for review; ${hintedCount} ${hintedCount === 1 ? 'has' : 'have'} an episode hint. Looking for show matches.`;
            void loadFilenameSuggestions(sourceName.value, sourceRevision);

            return;
        }

        statusMessage.value = `${videoCount} video ${videoCount === 1 ? 'file' : 'files'} found. ${excludedCount} excluded. ${blockingCount} blocking ${blockingCount === 1 ? 'issue' : 'issues'}.`;
    }

    async function uploadMoreEpisodes(): Promise<void> {
        const series = confirmedSeries.value;

        if (!series) {
            return;
        }

        interruptActiveTransfer();
        activeTusUpload = null;
        clearProcessingPoll();
        queueGeneration += 1;
        activeAuthorization = null;
        activeQueueIndex.value = null;
        admittedBatch.value = null;
        queueItems.value = [];
        selectedFiles.value = [];
        reviewMappings.value = [];
        reviewedMappings.value = null;
        confirmedReviewKey = null;
        selectedSeries.value = {
            tmdb_id: series.tmdb_id,
            name: series.name,
            original_name: series.original_name,
            first_air_year: series.first_air_year,
            overview: series.overview,
            poster_url: series.poster_url,
        };
        category.value = series.category;
        continuingSameShow = true;
        resetStorageState();
        currentStep.value = 1;
        statusMessage.value = `Choose more episode files for ${series.name}.`;
    }

    function goToSource(): void {
        if (admissionStarted.value) {
            return;
        }

        returnStepAfterSource =
            currentStep.value === 4
                ? 4
                : confirmedSeries.value === null
                  ? 2
                  : 3;
        currentStep.value = 1;
        hasReturnedToSource.value = true;
        statusMessage.value =
            'Returned to episode selection. Your selected files and review are unchanged.';
    }

    function keepCurrentVideos(): void {
        if (!canKeepCurrentVideos.value) {
            return;
        }

        currentStep.value = returnStepAfterSource;
        hasReturnedToSource.value = false;
        statusMessage.value =
            returnStepAfterSource === 4
                ? 'Current videos kept. Storage selection reopened.'
                : returnStepAfterSource === 3
                  ? 'Current videos kept. Episode review reopened.'
                  : 'Current videos kept. Show identification reopened.';
    }

    async function loadFilenameSuggestions(
        name: string,
        revision: number,
    ): Promise<void> {
        const requestId = cancelLookups();
        const requestedCategory = category.value;
        lastLookupKind = 'suggestion';
        lookupError.value = '';
        lookupCompleted.value = false;
        results.value = [];
        parsedSource.value = null;
        selectedSeries.value = null;
        filenameLookup.source_name = name;

        try {
            const response = await filenameLookup.get(
                suggestSeriesAction.url(),
                lookupOptions(requestId),
            );

            if (
                requestId !== lookupRequestId ||
                revision !== sourceRevision ||
                sourceName.value !== name ||
                category.value !== requestedCategory
            ) {
                return;
            }

            results.value = response.data;
            parsedSource.value = response.meta.parsed ?? null;
            searchInput.value = parsedSource.value?.title ?? name;
            lookupCompleted.value = true;
            statusMessage.value = response.data.length
                ? `${response.data.length} show matches found.`
                : 'No show matches found.';
        } catch {
            // Request callbacks announce safe failures while preserving local files.
        }
    }

    async function runSmartSearch(): Promise<void> {
        const query = searchInput.value.normalize('NFC').trim();

        if (!query) {
            lookupError.value = 'Enter a show title or numeric TMDB ID.';

            return;
        }

        if (/^tt\d+$/i.test(query)) {
            lookupError.value =
                'IMDb lookup is not supported for shows. Search by title or numeric TMDB ID.';

            return;
        }

        const requestId = cancelLookups();
        const revision = sourceRevision;
        const requestedCategory = category.value;
        lastLookupKind = 'search';
        searchInput.value = query;
        lookupError.value = '';
        lookupCompleted.value = false;
        results.value = [];
        parsedSource.value = null;
        selectedSeries.value = null;

        try {
            if (/^\d+$/.test(query)) {
                const tmdbId = Number(query);

                if (!Number.isSafeInteger(tmdbId) || tmdbId < 1) {
                    lookupError.value = 'Enter a valid numeric TMDB ID.';
                    lookupCompleted.value = true;

                    return;
                }

                const response = await detailsLookup.get(
                    showSeriesAction.url(tmdbId),
                    lookupOptions(requestId),
                );

                if (
                    isCurrentLookup(
                        requestId,
                        revision,
                        requestedCategory,
                        query,
                    )
                ) {
                    results.value = [response.data];
                    lookupCompleted.value = true;
                    statusMessage.value = `Exact match found for ${response.data.name}.`;
                }

                return;
            }

            textLookup.query = query;
            textLookup.year = '';
            const response = await textLookup.get(
                searchSeriesAction.url(),
                lookupOptions(requestId),
            );

            if (
                !isCurrentLookup(requestId, revision, requestedCategory, query)
            ) {
                return;
            }

            results.value = response.data;
            lookupCompleted.value = true;
            statusMessage.value = response.data.length
                ? `${response.data.length} show matches found.`
                : 'No show matches found.';
        } catch {
            // Request callbacks announce safe failures while preserving local files.
        }
    }

    function selectSeries(series: SeriesSearchResult): void {
        if (isLookingUp.value || isConfirming.value) {
            return;
        }

        selectedSeries.value = series;
        statusMessage.value = `${series.name} selected. Choose Select to continue.`;
    }

    async function confirmSeries(): Promise<void> {
        const series = selectedSeries.value;

        if (!series || !canContinue.value) {
            return;
        }

        cancelConfirmation();
        cancelSeasonHydrations();
        const requestId = confirmationRequestId;
        const revision = sourceRevision;
        const requestedCategory = category.value;
        const requestedSeasons = [...seasonNumbers.value];
        const requestedSeasonKey = requestedSeasons.join(',');
        const nextReviewKey = `${series.tmdb_id}:${requestedCategory}`;
        lookupError.value = '';
        confirmation.tmdb_id = series.tmdb_id;
        confirmation.category = requestedCategory;
        confirmation.season_numbers = requestedSeasons;

        try {
            const response = await confirmation.post(
                confirmSeriesAction.url(),
                {
                    onHttpException: () => {
                        if (requestId === confirmationRequestId) {
                            lookupError.value =
                                'Show confirmation failed. Please try again.';
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === confirmationRequestId) {
                            lookupError.value =
                                'Show confirmation failed. Check your connection and try again.';
                        }
                    },
                },
            );

            if (
                requestId !== confirmationRequestId ||
                revision !== sourceRevision ||
                selectedSeries.value?.tmdb_id !== series.tmdb_id ||
                category.value !== requestedCategory ||
                seasonNumbers.value.join(',') !== requestedSeasonKey
            ) {
                return;
            }

            confirmedSeries.value = response.data;

            if (confirmedReviewKey === nextReviewKey) {
                reconcileReviewMappings();
            } else {
                initializeReviewMappings(response.data);
                reviewedMappings.value = null;
            }

            confirmedReviewKey = nextReviewKey;
            currentStep.value = 3;
            statusMessage.value = `${response.data.name} confirmed. Review ${reviewMappings.value.length} episode ${reviewMappings.value.length === 1 ? 'assignment' : 'assignments'}.`;
        } catch {
            // Request callbacks announce safe failures while preserving local files.
        }
    }

    function initializeReviewMappings(series: ConfirmedSeries): void {
        const matchedSources = acceptedFiles.value.map((source) => ({
            ...source,
        }));
        const duplicateHints = duplicateEpisodeHintSourceKeys(matchedSources);

        reviewMappings.value = matchedSources.map((source) => {
            const hintedEpisode = source.hint
                ? episodeByNumbers(
                      series,
                      source.hint.seasonNumber,
                      source.hint.episodeNumber,
                  )
                : null;
            const hintedSeasonAvailable = source.hint
                ? series.available_seasons.some(
                      (season) =>
                          season.season_number === source.hint?.seasonNumber,
                  )
                : false;
            const shouldAssign = hintedEpisode !== null;

            return {
                sourceKey: source.sourceKey,
                hint: source.hint,
                selectedSeasonNumber: shouldAssign
                    ? (source.hint?.seasonNumber ?? null)
                    : hintedSeasonAvailable
                      ? (source.hint?.seasonNumber ?? null)
                      : null,
                selectedEpisodeNumber: shouldAssign
                    ? (source.hint?.episodeNumber ?? null)
                    : null,
                seriesEpisodeId: shouldAssign ? hintedEpisode.id : null,
                assignmentOrigin: source.hint ? 'auto' : null,
                validationStatus: duplicateHints.has(source.sourceKey)
                    ? 'conflict'
                    : 'needs_assignment',
                replacesMediaFileId:
                    shouldAssign && hintedEpisode.has_current_primary
                        ? (hintedEpisode.current_primary?.id ?? null)
                        : null,
                replacementConfirmed: false,
            } satisfies EpisodeReviewMapping;
        });
        rowSelectionRevisions.clear();
        refreshReviewValidation();
    }

    function reconcileReviewMappings(): void {
        const series = confirmedSeries.value;

        if (!series) {
            return;
        }

        reviewMappings.value.forEach((mapping) => {
            const episode = mapping.seriesEpisodeId
                ? episodeById(series, mapping.seriesEpisodeId)
                : null;

            if (episode) {
                const nextReplacementId = episode.has_current_primary
                    ? (episode.current_primary?.id ?? null)
                    : null;

                if (mapping.replacesMediaFileId !== nextReplacementId) {
                    mapping.replacementConfirmed = false;
                    reviewedMappings.value = null;
                }

                mapping.selectedSeasonNumber = seasonNumberForEpisode(
                    series,
                    episode.id,
                );
                mapping.selectedEpisodeNumber = episode.episode_number;
                mapping.replacesMediaFileId = nextReplacementId;
            } else if (mapping.assignmentOrigin === 'auto' && mapping.hint) {
                const hintedEpisode = episodeByNumbers(
                    series,
                    mapping.hint.seasonNumber,
                    mapping.hint.episodeNumber,
                );

                if (hintedEpisode) {
                    mapping.selectedSeasonNumber = mapping.hint.seasonNumber;
                    mapping.selectedEpisodeNumber = mapping.hint.episodeNumber;
                    mapping.seriesEpisodeId = hintedEpisode.id;
                    mapping.replacesMediaFileId =
                        hintedEpisode.has_current_primary
                            ? (hintedEpisode.current_primary?.id ?? null)
                            : null;
                }
            } else if (mapping.seriesEpisodeId !== null) {
                mapping.seriesEpisodeId = null;
                mapping.selectedEpisodeNumber = null;
                mapping.replacesMediaFileId = null;
                mapping.replacementConfirmed = false;
                reviewedMappings.value = null;
            }
        });
        refreshReviewValidation();
    }

    function setReviewSeason(
        sourceKey: string,
        seasonNumber: number | null,
    ): void {
        const mapping = mappingBySourceKey(sourceKey);

        if (!mapping) {
            return;
        }

        mapping.selectedSeasonNumber = seasonNumber;
        mapping.selectedEpisodeNumber = null;
        mapping.seriesEpisodeId = null;
        mapping.assignmentOrigin = 'manual';
        mapping.replacesMediaFileId = null;
        mapping.replacementConfirmed = false;
        reviewedMappings.value = null;
        bumpRowSelectionRevision(sourceKey);
        refreshReviewValidation();

        if (seasonNumber !== null && !isSeasonHydrated(seasonNumber)) {
            void hydrateSeason(seasonNumber, sourceKey);
        }
    }

    function setReviewEpisode(
        sourceKey: string,
        episodeId: number | null,
    ): void {
        const mapping = mappingBySourceKey(sourceKey);

        if (!mapping) {
            return;
        }

        const episode = episodeId === null ? null : episodeByIdValue(episodeId);

        mapping.seriesEpisodeId = episode?.id ?? null;
        mapping.selectedEpisodeNumber = episode?.episode_number ?? null;
        mapping.assignmentOrigin = 'manual';
        mapping.replacesMediaFileId = episode?.has_current_primary
            ? (episode.current_primary?.id ?? null)
            : null;
        mapping.replacementConfirmed = false;
        reviewedMappings.value = null;
        bumpRowSelectionRevision(sourceKey);
        refreshReviewValidation();
        statusMessage.value = episode
            ? `${mapping.sourceKey} assigned to episode ${episode.episode_number}.`
            : `${mapping.sourceKey} needs an episode assignment.`;
    }

    function setReplacementConfirmed(
        sourceKey: string,
        confirmed: boolean,
    ): void {
        const mapping = mappingBySourceKey(sourceKey);
        const episode = mapping ? episodeForMapping(mapping) : null;

        if (
            !mapping ||
            !episode?.has_current_primary ||
            !episode.can_replace_current_primary
        ) {
            return;
        }

        mapping.replacementConfirmed = confirmed;
        mapping.replacesMediaFileId = episode.current_primary?.id ?? null;
        reviewedMappings.value = null;
        refreshReviewValidation();
        statusMessage.value = confirmed
            ? `Replacement of ${episode.identity} explicitly confirmed.`
            : `Replacement confirmation removed from ${episode.identity}.`;
    }

    function previewBulkAssignment(
        sourceKeys: string[],
        seasonNumber: number,
        startingEpisodeNumber: number,
    ): SequentialAssignmentPlan {
        const season = confirmedSeries.value?.seasons.find(
            (candidate) => candidate.season_number === seasonNumber,
        );
        const sources = sourceKeys.flatMap((sourceKey) => {
            const mapping = mappingBySourceKey(sourceKey);

            return mapping
                ? [
                      {
                          sourceKey,
                          assignmentOrigin: mapping.assignmentOrigin,
                      },
                  ]
                : [];
        });
        const existingAssignments = reviewMappings.value.flatMap((mapping) =>
            mapping.seriesEpisodeId === null
                ? []
                : [
                      {
                          sourceKey: mapping.sourceKey,
                          episodeId: mapping.seriesEpisodeId,
                      },
                  ],
        );

        return planSequentialAssignments(
            sources,
            (season?.episodes ?? []).map((episode) => ({
                id: episode.id,
                episodeNumber: episode.episode_number,
            })),
            startingEpisodeNumber,
            existingAssignments,
        );
    }

    function applyBulkAssignment(
        sourceKeys: string[],
        seasonNumber: number,
        startingEpisodeNumber: number,
    ): boolean {
        const plan = previewBulkAssignment(
            sourceKeys,
            seasonNumber,
            startingEpisodeNumber,
        );

        if (plan.conflicts.length > 0 || plan.assignments.length === 0) {
            statusMessage.value =
                'Sequential assignment was not applied because its preview has conflicts.';

            return false;
        }

        plan.assignments.forEach((assignment) => {
            const mapping = mappingBySourceKey(assignment.sourceKey);
            const episode = episodeByIdValue(assignment.episodeId);

            if (!mapping || !episode || mapping.assignmentOrigin === 'manual') {
                return;
            }

            mapping.selectedSeasonNumber = seasonNumber;
            mapping.selectedEpisodeNumber = episode.episode_number;
            mapping.seriesEpisodeId = episode.id;
            mapping.assignmentOrigin = 'bulk';
            mapping.replacesMediaFileId = episode.has_current_primary
                ? (episode.current_primary?.id ?? null)
                : null;
            mapping.replacementConfirmed = false;
            bumpRowSelectionRevision(mapping.sourceKey);
        });
        reviewedMappings.value = null;
        refreshReviewValidation();
        statusMessage.value = `${plan.assignments.length} ${plan.assignments.length === 1 ? 'file was' : 'files were'} assigned in order.`;

        return true;
    }

    async function hydrateSeason(
        seasonNumber: number,
        sourceKey: string | null = null,
    ): Promise<void> {
        const series = confirmedSeries.value;

        if (!series || isSeasonHydrated(seasonNumber)) {
            return;
        }

        const revision = sourceRevision;
        const seriesId = series.id;
        const requestedCategory = series.category;
        const rowRevision = sourceKey
            ? (rowSelectionRevisions.get(sourceKey) ?? 0)
            : null;
        let pending = pendingSeasonHydrations.get(seasonNumber);

        if (!pending) {
            const request = createSeasonHydrationRequest();
            seasonHydrationStates.value = {
                ...seasonHydrationStates.value,
                [seasonNumber]: 'loading',
            };
            seasonHydrationErrors.value = {
                ...seasonHydrationErrors.value,
                [seasonNumber]: undefined,
            };
            const promise = request.post(
                hydrateSeasonAction.url({
                    series: seriesId,
                    seasonNumber,
                }),
            );
            pending = { request, promise };
            pendingSeasonHydrations.set(seasonNumber, pending);
            const removePendingRequest = (): void => {
                if (
                    pendingSeasonHydrations.get(seasonNumber)?.request ===
                    request
                ) {
                    pendingSeasonHydrations.delete(seasonNumber);
                }
            };
            void promise.then(removePendingRequest, removePendingRequest);
        }

        try {
            const response = await pending.promise;

            if (
                revision !== sourceRevision ||
                confirmedSeries.value?.id !== seriesId ||
                confirmedSeries.value.category !== requestedCategory
            ) {
                return;
            }

            if (
                sourceKey !== null &&
                ((rowSelectionRevisions.get(sourceKey) ?? 0) !== rowRevision ||
                    mappingBySourceKey(sourceKey)?.selectedSeasonNumber !==
                        seasonNumber)
            ) {
                seasonHydrationStates.value = {
                    ...seasonHydrationStates.value,
                    [seasonNumber]: 'idle',
                };

                return;
            }

            confirmedSeries.value = response.data;
            seasonHydrationStates.value = {
                ...seasonHydrationStates.value,
                [seasonNumber]: 'idle',
            };
            reconcileReviewMappings();
            statusMessage.value = `${seasonLabel(seasonNumber)} episode choices loaded.`;
        } catch {
            if (
                revision !== sourceRevision ||
                confirmedSeries.value?.id !== seriesId ||
                (sourceKey !== null &&
                    (rowSelectionRevisions.get(sourceKey) ?? 0) !== rowRevision)
            ) {
                return;
            }

            seasonHydrationStates.value = {
                ...seasonHydrationStates.value,
                [seasonNumber]: 'error',
            };
            seasonHydrationErrors.value = {
                ...seasonHydrationErrors.value,
                [seasonNumber]: `${seasonLabel(seasonNumber)} could not be loaded. Try again.`,
            };
            statusMessage.value =
                seasonHydrationErrors.value[seasonNumber] ?? '';
        }
    }

    function confirmEpisodeReview(): void {
        if (!isReviewReady.value) {
            statusMessage.value =
                'Resolve every episode assignment and replacement before continuing.';

            return;
        }

        reviewedMappings.value = reviewMappings.value.map((mapping) => ({
            sourceKey: mapping.sourceKey,
            seriesEpisodeId: mapping.seriesEpisodeId as number,
            replacesMediaFileId: mapping.replacesMediaFileId,
            replacementConfirmed: mapping.replacementConfirmed,
        }));
        currentStep.value = 4;
        statusMessage.value =
            'Episode review confirmed. Step 4 of 6, choose storage.';
        void requestStoragePreview();
    }

    function returnToReview(): void {
        if (!confirmedSeries.value || admissionStarted.value) {
            return;
        }

        currentStep.value = 3;
        statusMessage.value =
            'Episode review reopened with every assignment unchanged.';
    }

    function currentStorageContext(): string | null {
        const series = confirmedSeries.value;
        const mappings = reviewedMappings.value;

        if (!series || !mappings) {
            return null;
        }

        return JSON.stringify({
            sourceRevision,
            seriesId: series.id,
            category: series.category,
            mappings,
            sources: acceptedFiles.value.map((source) => ({
                key: source.sourceKey,
                name: source.file.name,
                size: source.file.size,
                lastModified: source.file.lastModified,
            })),
        });
    }

    function previewItems(): PreviewSeriesBatchRequest['items'] {
        const mappings = reviewedMappings.value ?? [];
        const sources = new Map(
            acceptedFiles.value.map((source) => [source.sourceKey, source]),
        );

        return mappings.map((mapping) => {
            const source = sources.get(mapping.sourceKey);

            if (!source) {
                throw new Error('A reviewed local source is unavailable.');
            }

            return {
                source_identity: source.sourceKey,
                series_episode_id: mapping.seriesEpisodeId,
                declared_size: source.file.size,
                replaces_media_file_id: mapping.replacesMediaFileId,
                replacement_confirmed: mapping.replacementConfirmed,
            };
        });
    }

    async function requestStoragePreview(
        isRefresh = false,
        preserveError = false,
    ): Promise<void> {
        const series = confirmedSeries.value;
        const context = currentStorageContext();

        if (!series || !context || admissionRequest.processing) {
            return;
        }

        if (
            !isRefresh &&
            storageContextKey === context &&
            storagePreview.value
        ) {
            if (
                series.home_disk_id !== null &&
                autoAdmissionAttemptedContext !== context
            ) {
                autoAdmissionAttemptedContext = context;
                void prepareBatch(series.home_disk_id, true);
            }

            return;
        }

        if (storageContextKey !== context) {
            resetStorageState();
            storageContextKey = context;
        }

        storageRequestId += 1;
        const requestId = storageRequestId;

        if (!preserveError) {
            storageError.value = '';
        }

        previewRequest.items = previewItems();

        try {
            const response = await previewRequest.post(
                previewSeriesBatchAction.url(series.id),
                {
                    onHttpException: (exception) => {
                        if (requestId === storageRequestId) {
                            storageError.value = readHttpError(
                                exception.data,
                                'Storage planning failed. Please try again.',
                            );
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === storageRequestId) {
                            storageError.value =
                                'Storage planning failed. Check your connection and try again.';
                        }
                    },
                },
            );

            if (
                requestId !== storageRequestId ||
                context !== currentStorageContext()
            ) {
                return;
            }

            storagePreview.value = response.data;
            selectedDiskId.value = '';
            statusMessage.value = response.data.can_start_batch
                ? series.home_disk_id === null
                    ? 'Storage options ready. Choose a disk to prepare the batch.'
                    : 'Assigned storage is ready. Preparing the batch automatically.'
                : 'The batch is blocked by its available storage.';

            if (
                series.home_disk_id !== null &&
                response.data.can_start_batch &&
                autoAdmissionAttemptedContext !== context
            ) {
                autoAdmissionAttemptedContext = context;
                void prepareBatch(series.home_disk_id, true);
            }
        } catch {
            // The request callbacks retain a safe, actionable message.
        }
    }

    async function prepareBatch(
        diskId: string,
        automatic = false,
    ): Promise<void> {
        const series = confirmedSeries.value;
        const preview = storagePreview.value;
        const context = currentStorageContext();
        const disk = preview?.disks.find(
            (candidate) => candidate.id === diskId,
        );

        if (
            !series ||
            !preview ||
            !context ||
            !disk?.eligible ||
            isAdmitting.value
        ) {
            if (automatic && disk && !disk.eligible) {
                storageError.value =
                    disk.reasons[0]?.message ??
                    'The assigned storage disk cannot accept this batch.';
            }

            return;
        }

        selectedDiskId.value = diskId;
        admissionStarted.value = true;
        admissionRequestId += 1;
        const requestId = admissionRequestId;
        let fingerprintsReady = false;
        storageError.value = '';

        try {
            const fingerprints = await fingerprintsForContext(context);
            fingerprintsReady = true;

            if (
                requestId !== admissionRequestId ||
                context !== currentStorageContext()
            ) {
                return;
            }

            if (idempotencyContextKey !== `${context}:${diskId}`) {
                idempotencyContextKey = `${context}:${diskId}`;
                idempotencyKey = crypto.randomUUID();
            }

            const mappings = reviewedMappings.value ?? [];
            const sources = new Map(
                acceptedFiles.value.map((source) => [source.sourceKey, source]),
            );
            admissionRequest.idempotency_key = idempotencyKey as string;
            admissionRequest.disk_id = diskId;
            admissionRequest.items = mappings.map((mapping) => {
                const source = sources.get(mapping.sourceKey);
                const fingerprint = fingerprints.get(mapping.sourceKey);

                if (!source || !fingerprint) {
                    throw new Error(
                        'A reviewed source fingerprint is missing.',
                    );
                }

                return {
                    source_identity: source.sourceKey,
                    series_episode_id: mapping.seriesEpisodeId,
                    declared_size: source.file.size,
                    last_modified_milliseconds:
                        fingerprint.last_modified_milliseconds,
                    fingerprint_first_sha256:
                        fingerprint.fingerprint_first_sha256,
                    fingerprint_last_sha256:
                        fingerprint.fingerprint_last_sha256,
                    replaces_media_file_id: mapping.replacesMediaFileId,
                    replacement_confirmed: mapping.replacementConfirmed,
                };
            });
            const response = await admissionRequest.post(
                storeSeriesBatchAction.url(series.id),
                {
                    onHttpException: (exception) => {
                        if (requestId === admissionRequestId) {
                            storageError.value = readHttpError(
                                exception.data,
                                'The batch could not be admitted. Review storage and retry.',
                            );
                        }
                    },
                    onNetworkError: () => {
                        if (requestId === admissionRequestId) {
                            storageError.value =
                                'The admission result is unknown. Retry safely with the same request key.';
                        }
                    },
                },
            );

            if (
                requestId !== admissionRequestId ||
                context !== currentStorageContext()
            ) {
                return;
            }

            admittedBatch.value = response.data;
            initializeQueue(response.data, fingerprints);
            currentStep.value = 5;
            statusMessage.value = `Batch prepared on ${response.data.home_disk.label ?? response.data.home_disk.id}. Starting the first episode.`;
            await startNextQueueItem();
        } catch {
            if (requestId !== admissionRequestId) {
                return;
            }

            if (!storageError.value) {
                storageError.value = !fingerprintsReady
                    ? 'A file could not be fingerprinted. Keep the files available and retry.'
                    : 'The batch could not be prepared safely.';
            }

            if (!admittedBatch.value) {
                admissionStarted.value = true;
                void requestStoragePreview(true, true);
            }
        }
    }

    function retryStorage(): void {
        const assignedDiskId = confirmedSeries.value?.home_disk_id;

        if (assignedDiskId && storagePreview.value) {
            void prepareBatch(assignedDiskId);

            return;
        }

        void requestStoragePreview(true);
    }

    async function fingerprintsForContext(
        context: string,
    ): Promise<Map<string, UploadFingerprint>> {
        if (storageContextKey !== context) {
            fingerprintCache.clear();
        }

        isFingerprinting.value = true;
        fingerprintProgress.value = {
            completed: 0,
            total: acceptedFiles.value.length,
            filename: '',
        };

        try {
            for (const source of acceptedFiles.value) {
                fingerprintProgress.value = {
                    ...fingerprintProgress.value,
                    filename: source.file.name,
                };

                if (!fingerprintCache.has(source.sourceKey)) {
                    fingerprintCache.set(
                        source.sourceKey,
                        await fingerprintUploadFile(
                            source.file,
                            fingerprintWindowBytes,
                        ),
                    );
                }

                fingerprintProgress.value = {
                    completed: fingerprintProgress.value.completed + 1,
                    total: acceptedFiles.value.length,
                    filename: source.file.name,
                };
            }

            return new Map(fingerprintCache);
        } finally {
            isFingerprinting.value = false;
        }
    }

    async function loadResumableBatches(): Promise<void> {
        try {
            const response = await resumableRequest.get(
                indexSeriesBatchesAction.url(),
            );
            resumableBatches.value = response.data;
        } catch {
            recoveryError.value =
                'Unfinished Show uploads could not be checked. You can retry by reloading this page.';
        }
    }

    async function recoverBatch(
        batch: SeriesBatch,
        files: File[],
    ): Promise<void> {
        if (isRecovering.value) {
            return;
        }

        recoveryError.value = '';
        const matched = matchSeriesRecoveryFiles(batch.items, files);

        if (matched.missing.length > 0 || matched.ambiguous.length > 0) {
            recoveryError.value = matched.ambiguous.length
                ? 'Some selected filenames are ambiguous. Select their original folder structure so every episode has one exact match.'
                : `Select all ${batch.items.filter((item) => ['pending', 'uploading', 'paused'].includes(item.status)).length} files that still need transfer.`;

            return;
        }

        isRecovering.value = true;

        try {
            const fingerprints = new Map<string, UploadFingerprint>();
            recoveryRequest.items = [];

            for (const match of matched.matches) {
                const fingerprint = await fingerprintUploadFile(
                    match.file,
                    fingerprintWindowBytes,
                );
                fingerprints.set(match.uploadUuid, fingerprint);
                recoveryRequest.items.push({
                    upload_uuid: match.uploadUuid,
                    source_identity: match.sourceIdentity,
                    ...fingerprint,
                });
            }

            const response = await recoveryRequest.post(
                recoverSeriesBatchAction.url(batch.uuid),
                {
                    onHttpException: (exception) => {
                        recoveryError.value = readHttpError(
                            exception.data,
                            'The selected files could not verify this batch.',
                        );
                    },
                    onNetworkError: () => {
                        recoveryError.value =
                            'Recovery could not be verified. Check your connection and retry the same selection.';
                    },
                },
            );
            const filesByUuid = new Map(
                matched.matches.map((match) => [match.uploadUuid, match.file]),
            );
            admittedBatch.value = response.data;
            admissionStarted.value = true;
            initializeRecoveredQueue(response.data, filesByUuid, fingerprints);
            resumableBatches.value = [];
            currentStep.value = 5;
            statusMessage.value = `Recovered ${response.data.series.name}. Resuming from the confirmed server offset.`;
            await startNextQueueItem();
        } catch {
            recoveryError.value ||=
                'The selected files could not verify this batch.';
        } finally {
            isRecovering.value = false;
        }
    }

    function initializeQueue(
        batch: SeriesBatch,
        fingerprints: Map<string, UploadFingerprint>,
    ): void {
        queueGeneration += 1;
        const mappingByEpisode = new Map(
            (reviewedMappings.value ?? []).map((mapping) => [
                mapping.seriesEpisodeId,
                mapping,
            ]),
        );
        const sourceByKey = new Map(
            acceptedFiles.value.map((source) => [source.sourceKey, source]),
        );

        queueItems.value = batch.items.map((batchItem) => {
            const mapping = mappingByEpisode.get(batchItem.episode.id);
            const source = mapping ? sourceByKey.get(mapping.sourceKey) : null;
            const fingerprint = mapping
                ? fingerprints.get(mapping.sourceKey)
                : null;

            if (!mapping || !source || !fingerprint) {
                throw new Error(
                    'An admitted episode no longer maps to its local file.',
                );
            }

            return {
                batchItem,
                sourceKey: mapping.sourceKey,
                file: source.file,
                fingerprint,
                status: batchItem.status as SeriesUploadSession['status'],
                confirmedBytes: batchItem.confirmed_bytes,
                failure: null,
            };
        });
    }

    function initializeRecoveredQueue(
        batch: SeriesBatch,
        filesByUuid: Map<string, File>,
        fingerprints: Map<string, UploadFingerprint>,
    ): void {
        queueGeneration += 1;
        queueItems.value = batch.items.map((batchItem) => ({
            batchItem,
            sourceKey: batchItem.source_identity,
            file: filesByUuid.get(batchItem.upload_uuid) ?? null,
            fingerprint: fingerprints.get(batchItem.upload_uuid) ?? null,
            status: batchItem.status as SeriesUploadSession['status'],
            confirmedBytes: batchItem.confirmed_bytes,
            failure: batchItem.failure,
        }));
    }

    async function startNextQueueItem(): Promise<void> {
        clearProcessingPoll();
        const nextIndex = nextSeriesQueueIndex(queueItems.value);

        if (nextIndex === null) {
            await confirmBatchCompletion();

            return;
        }

        const nextItem = queueItems.value[nextIndex];
        activeQueueIndex.value = nextIndex;

        if (nextItem.status === 'failed' || nextItem.status === 'expired') {
            connectionState.value = 'error';
            uploadError.value =
                nextItem.status === 'expired'
                    ? 'This episode session expired. Acknowledge Skip to release it and continue.'
                    : (nextItem.failure?.detail ??
                      (nextItem.batchItem.actions.retry ||
                      nextItem.batchItem.actions.cancel
                          ? 'Validation stopped. Choose an available safe action.'
                          : 'This failure has claimed replacement state and cannot be retried or discarded here. Resolve the retained server state before continuing.'));
            statusMessage.value = uploadError.value;

            return;
        }

        if (nextItem.status === 'processing') {
            connectionState.value = 'received';
            scheduleProcessingPoll(
                nextItem.batchItem.upload_uuid,
                1000,
                queueGeneration,
            );

            return;
        }

        if (!nextItem.batchItem.actions.authorize) {
            connectionState.value = 'error';
            uploadError.value =
                'This episode cannot continue until its server state is resolved.';

            return;
        }

        await authorizeAndStart(nextIndex);
    }

    async function confirmBatchCompletion(): Promise<void> {
        const batch = admittedBatch.value;

        if (!batch) {
            return;
        }

        const generation = queueGeneration;
        connectionState.value = 'received';
        uploadError.value = '';

        try {
            const response = await batchRequest.get(
                showSeriesBatchAction.url(batch.uuid),
            );

            if (generation !== queueGeneration) {
                return;
            }

            const existingByUuid = new Map(
                queueItems.value.map((item) => [
                    item.batchItem.upload_uuid,
                    item,
                ]),
            );
            admittedBatch.value = response.data;
            queueItems.value = response.data.items.map((batchItem) => {
                const existing = existingByUuid.get(batchItem.upload_uuid);

                return {
                    batchItem,
                    sourceKey: batchItem.source_identity,
                    file: existing?.file ?? null,
                    fingerprint: existing?.fingerprint ?? null,
                    status: batchItem.status as SeriesUploadSession['status'],
                    confirmedBytes: batchItem.confirmed_bytes,
                    failure: batchItem.failure,
                };
            });

            if (
                queueItems.value.every((item) =>
                    ['completed', 'cancelled'].includes(item.status),
                )
            ) {
                activeQueueIndex.value = null;
                activeAuthorization = null;
                currentStep.value = 6;
                statusMessage.value = `${completedCount.value} uploaded and ${skippedCount.value} skipped. The batch is complete.`;

                return;
            }

            uploadError.value =
                'The final batch state changed on the server. Review the unresolved episode and retry.';
            await startNextQueueItem();
        } catch {
            uploadError.value =
                'The final batch summary could not be confirmed. Retry reconciliation to finish.';
            connectionState.value = 'error';
        }
    }

    function retryBatchReconciliation(): void {
        uploadError.value = '';
        void confirmBatchCompletion();
    }

    async function authorizeAndStart(index: number): Promise<void> {
        const item = queueItems.value[index];
        const generation = queueGeneration;

        if (!item || !item.file || !item.fingerprint) {
            uploadError.value =
                'Reselect the exact local file before resuming this episode.';
            connectionState.value = 'error';

            return;
        }

        connectionState.value = 'authorizing';
        uploadError.value = '';

        try {
            const authorization = await requestAuthorization(item);

            if (
                generation !== queueGeneration ||
                activeQueueIndex.value !== index ||
                queueItems.value[index]?.batchItem.upload_uuid !==
                    item.batchItem.upload_uuid
            ) {
                return;
            }

            activeAuthorization = authorization;
            applySession(authorization, false);
            startActiveTransfer();
        } catch {
            connectionState.value = navigator.onLine ? 'error' : 'offline';
            uploadError.value ||=
                'The episode could not be authorized. Retry when ready.';
            statusMessage.value = uploadError.value;
        }
    }

    async function requestAuthorization(
        item: SeriesQueueItem,
    ): Promise<SeriesUploadAuthorizationResponse['data']> {
        if (!item.fingerprint) {
            throw new Error('The local file fingerprint is unavailable.');
        }

        Object.assign(authorizationRequest, item.fingerprint);
        const response = await authorizationRequest.post(
            UploadAuthorizationController.url(item.batchItem.upload_uuid),
            {
                onHttpException: (exception) => {
                    uploadError.value = readHttpError(
                        exception.data,
                        'Upload authorization failed.',
                    );
                },
                onNetworkError: () => {
                    uploadError.value =
                        'Upload authorization failed. Check your connection and retry.';
                },
            },
        );

        return response.data;
    }

    async function refreshActiveAuthorization(force = false): Promise<string> {
        const index = activeQueueIndex.value;
        const item = index === null ? null : queueItems.value[index];
        const generation = queueGeneration;

        if (!item || !activeAuthorization) {
            throw new Error('The active upload authorization is unavailable.');
        }

        const expiresAt = Date.parse(
            activeAuthorization.authorization.expires_at,
        );
        const refreshAt =
            expiresAt -
            activeAuthorization.transport.token_refresh_leeway_seconds * 1000;

        if (!force && Number.isFinite(expiresAt) && Date.now() < refreshAt) {
            return activeAuthorization.authorization.token;
        }

        if (!authorizationPromise) {
            authorizationPromise = (async () => {
                connectionState.value = 'authorizing';
                const authorization = await requestAuthorization(item);

                if (
                    generation !== queueGeneration ||
                    activeQueueItem.value?.batchItem.upload_uuid !==
                        item.batchItem.upload_uuid
                ) {
                    throw new Error(
                        'The upload queue changed during authorization.',
                    );
                }

                activeAuthorization = authorization;
                applySession(activeAuthorization, false);
            })().finally(() => {
                authorizationPromise = null;
            });
        }

        await authorizationPromise;

        if (!activeAuthorization) {
            throw new Error(
                'The refreshed upload authorization is unavailable.',
            );
        }

        return activeAuthorization.authorization.token;
    }

    function startActiveTransfer(): void {
        const index = activeQueueIndex.value;
        const item = index === null ? null : queueItems.value[index];
        const authorization = activeAuthorization;

        if (!item || !item.file || !authorization || activeTusUpload) {
            return;
        }

        const generation = queueGeneration;
        const uploadUuid = item.batchItem.upload_uuid;

        lastProgressAt = performance.now();
        lastProgressBytes = item.confirmedBytes;
        speedBytesPerSecond.value = 0;
        etaSeconds.value = null;
        connectionState.value = 'uploading';
        activeTusUpload = createUploadTransport({
            source: item.file,
            uploadUuid: item.batchItem.upload_uuid,
            endpoint: authorization.endpoint,
            uploadUrl: authorization.resource_url,
            authorizationToken: authorization.authorization.token,
            settings: authorization.transport,
            refreshAuthorization: () => refreshActiveAuthorization(),
            onUploadUrlAvailable: (url) => {
                if (
                    generation === queueGeneration &&
                    activeQueueItem.value?.batchItem.upload_uuid ===
                        uploadUuid &&
                    activeAuthorization
                ) {
                    activeAuthorization.resource_url = url;
                }

                void refreshActiveSessionForTransfer(uploadUuid, generation);
            },
            onProgress: (bytesSent, bytesTotal) => {
                if (
                    generation === queueGeneration &&
                    activeQueueItem.value?.batchItem.upload_uuid === uploadUuid
                ) {
                    updateActiveProgress(bytesSent, bytesTotal);
                }
            },
            onRetry: (error) => {
                if (generation !== queueGeneration) {
                    return false;
                }

                connectionState.value = navigator.onLine
                    ? 'retrying'
                    : 'offline';
                statusMessage.value = navigator.onLine
                    ? 'Connection interrupted. Retrying the current episode.'
                    : 'Connection lost. Uploaded chunks remain safely staged.';
                const status = error.originalResponse?.getStatus() ?? null;

                return status === null || status === 409 || status >= 500;
            },
            onError: () => {
                if (
                    generation !== queueGeneration ||
                    activeQueueItem.value?.batchItem.upload_uuid !== uploadUuid
                ) {
                    return;
                }

                activeTusUpload = null;
                speedBytesPerSecond.value = 0;
                etaSeconds.value = null;
                connectionState.value = navigator.onLine ? 'error' : 'offline';
                uploadError.value = navigator.onLine
                    ? 'The transfer stopped safely. Retry to continue from the server offset.'
                    : 'The browser is offline. Reconnect, then retry the episode.';
                statusMessage.value = uploadError.value;
            },
            onSuccess: () => {
                if (
                    generation !== queueGeneration ||
                    activeQueueItem.value?.batchItem.upload_uuid !== uploadUuid
                ) {
                    return;
                }

                activeTusUpload = null;
                item.confirmedBytes =
                    item.file?.size ?? item.batchItem.declared_bytes;
                speedBytesPerSecond.value = 0;
                etaSeconds.value = 0;
                connectionState.value = 'received';
                statusMessage.value = `${item.batchItem.episode.identity} received. Validating on the server.`;
                void waitForServerState(item.batchItem.upload_uuid, generation);
            },
        });
        activeTusUpload.start();
        statusMessage.value = `${item.batchItem.episode.identity} upload started.`;
    }

    async function refreshActiveSessionForTransfer(
        uuid: string,
        generation: number,
    ): Promise<void> {
        try {
            const response = await statusRequest.get(
                UploadController.show.url(uuid),
            );

            if (
                generation === queueGeneration &&
                activeQueueItem.value?.batchItem.upload_uuid === uuid
            ) {
                applySession(response.data, false, generation);
                connectionState.value = 'uploading';
            }
        } catch {
            // Transfer remains active; the next lifecycle request reconciles state.
        }
    }

    function updateActiveProgress(bytesSent: number, bytesTotal: number): void {
        const item = activeQueueItem.value;

        if (!item) {
            return;
        }

        const now = performance.now();
        const elapsedSeconds = (now - lastProgressAt) / 1000;
        const acceptedBytes = bytesSent - lastProgressBytes;

        if (elapsedSeconds >= 0.25 && acceptedBytes >= 0) {
            const instantSpeed = acceptedBytes / elapsedSeconds;
            speedBytesPerSecond.value = speedBytesPerSecond.value
                ? speedBytesPerSecond.value * 0.7 + instantSpeed * 0.3
                : instantSpeed;
            lastProgressAt = now;
            lastProgressBytes = bytesSent;
        }

        item.confirmedBytes = bytesSent;
        item.status = 'uploading';
        etaSeconds.value =
            speedBytesPerSecond.value > 0
                ? Math.max(bytesTotal - bytesSent, 0) /
                  speedBytesPerSecond.value
                : null;
        connectionState.value = 'uploading';

        if (now - lastProgressAnnouncementAt >= 1000) {
            lastProgressAnnouncementAt = now;
            statusMessage.value = `${item.batchItem.episode.identity}: ${Math.floor((bytesSent / bytesTotal) * 100)} percent uploaded.`;
        }
    }

    async function waitForServerState(
        uuid: string,
        generation = queueGeneration,
    ): Promise<void> {
        for (let attempt = 0; attempt < 10; attempt += 1) {
            try {
                const response = await statusRequest.get(
                    UploadController.show.url(uuid),
                );

                if (
                    generation !== queueGeneration ||
                    activeQueueItem.value?.batchItem.upload_uuid !== uuid
                ) {
                    return;
                }

                applySession(response.data, true, generation);

                if (response.data.status !== 'uploading') {
                    return;
                }
            } catch {
                // A later poll reconciles the server-owned state.
            }

            await new Promise((resolve) => window.setTimeout(resolve, 500));
        }

        scheduleProcessingPoll(uuid, 1000, generation);
    }

    function applySession(
        session: SeriesUploadSession,
        advanceTerminal = true,
        generation = queueGeneration,
    ): void {
        if (generation !== queueGeneration) {
            return;
        }

        const item = queueItems.value.find(
            (candidate) => candidate.batchItem.upload_uuid === session.uuid,
        );

        if (!item) {
            return;
        }

        item.status = session.status;
        item.confirmedBytes = session.confirmed_bytes;
        item.failure = session.failure;
        item.batchItem.status = session.status;
        item.batchItem.confirmed_bytes = session.confirmed_bytes;
        item.batchItem.failure = session.failure;
        item.batchItem.actions = session.actions;
        item.batchItem.finalized =
            session.finalized ?? item.batchItem.finalized;

        if (
            session.status === 'processing' ||
            (session.status === 'uploading' && advanceTerminal)
        ) {
            connectionState.value = 'received';
            statusMessage.value = `${item.batchItem.episode.identity} is being validated.`;
            scheduleProcessingPoll(
                session.uuid,
                session.poll_interval_milliseconds,
                generation,
            );

            return;
        }

        clearProcessingPoll();

        if (session.status === 'failed' || session.status === 'expired') {
            connectionState.value = 'error';
            uploadError.value =
                session.failure?.detail ??
                'Validation failed safely. Resolve this episode before continuing.';
            statusMessage.value = uploadError.value;

            return;
        }

        if (
            advanceTerminal &&
            (session.status === 'completed' || session.status === 'cancelled')
        ) {
            statusMessage.value =
                session.status === 'completed'
                    ? `${item.batchItem.episode.identity} completed. Starting the next episode.`
                    : `${item.batchItem.episode.identity} skipped. Starting the next episode.`;
            void reconcileBatchAfterTerminal(generation);
        }
    }

    async function reconcileBatchAfterTerminal(
        generation: number,
    ): Promise<void> {
        const batch = admittedBatch.value;

        if (!batch) {
            return;
        }

        try {
            const response = await batchRequest.get(
                showSeriesBatchAction.url(batch.uuid),
            );

            if (generation !== queueGeneration) {
                return;
            }

            const existingByUuid = new Map(
                queueItems.value.map((item) => [
                    item.batchItem.upload_uuid,
                    item,
                ]),
            );
            admittedBatch.value = response.data;
            queueItems.value = response.data.items.map((batchItem) => {
                const existing = existingByUuid.get(batchItem.upload_uuid);

                return {
                    batchItem,
                    sourceKey: batchItem.source_identity,
                    file: existing?.file ?? null,
                    fingerprint: existing?.fingerprint ?? null,
                    status: batchItem.status as SeriesUploadSession['status'],
                    confirmedBytes: batchItem.confirmed_bytes,
                    failure: batchItem.failure,
                };
            });
            await startNextQueueItem();
        } catch {
            uploadError.value =
                'The next episode could not be reconciled safely. Retry the batch.';
            connectionState.value = 'error';
        }
    }

    function scheduleProcessingPoll(
        uuid: string,
        interval: number,
        generation = queueGeneration,
    ): void {
        clearProcessingPoll();
        processingPollTimer = window.setTimeout(
            () => void pollProcessingStatus(uuid, generation),
            Math.min(Math.max(interval, 500), 10_000),
        );
    }

    async function pollProcessingStatus(
        uuid: string,
        generation: number,
    ): Promise<void> {
        processingPollTimer = null;

        if (
            generation !== queueGeneration ||
            activeQueueItem.value?.batchItem.upload_uuid !== uuid
        ) {
            return;
        }

        try {
            const response = await statusRequest.get(
                UploadController.show.url(uuid),
            );
            applySession(response.data, true, generation);
        } catch {
            if (
                generation === queueGeneration &&
                activeQueueItem.value?.batchItem.upload_uuid === uuid
            ) {
                scheduleProcessingPoll(uuid, 1000, generation);
            }
        }
    }

    async function pauseUpload(): Promise<void> {
        const item = activeQueueItem.value;
        const generation = queueGeneration;

        if (
            !item ||
            !item.batchItem.actions.pause ||
            !activeTusUpload ||
            pauseRequest.processing
        ) {
            return;
        }

        await activeTusUpload.abort(false);
        activeTusUpload = null;

        try {
            const response = await pauseRequest.post(
                UploadPauseController.url(item.batchItem.upload_uuid),
            );

            if (generation !== queueGeneration) {
                return;
            }

            applySession(response.data, false);
            connectionState.value = 'paused';
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            statusMessage.value = `${item.batchItem.episode.identity} paused safely.`;
        } catch {
            connectionState.value = 'error';
            uploadError.value =
                'The transfer stopped, but its paused state could not be confirmed.';
        }
    }

    async function retryUpload(): Promise<void> {
        const index = activeQueueIndex.value;

        if (
            index === null ||
            activeTusUpload ||
            !queueItems.value[index]?.batchItem.actions.authorize
        ) {
            return;
        }

        activeAuthorization = null;
        await authorizeAndStart(index);
    }

    async function retryValidation(): Promise<void> {
        const item = activeQueueItem.value;
        const generation = queueGeneration;

        if (
            !item ||
            item.status !== 'failed' ||
            !item.batchItem.actions.retry
        ) {
            return;
        }

        uploadError.value = '';

        try {
            const response = await retryRequest.post(
                UploadController.retry.url(item.batchItem.upload_uuid),
            );
            applySession(response.data, true, generation);
        } catch {
            uploadError.value ||= 'Validation could not be retried safely.';
        }
    }

    async function skipCurrentEpisode(): Promise<void> {
        const item = activeQueueItem.value;
        const generation = queueGeneration;

        if (
            !item ||
            !item.batchItem.actions.cancel ||
            cancelRequest.processing
        ) {
            return;
        }

        if (activeTusUpload) {
            await activeTusUpload.abort(false);
            activeTusUpload = null;
        }

        try {
            const response = await cancelRequest.delete(
                UploadController.destroy.url(item.batchItem.upload_uuid),
            );

            if (generation !== queueGeneration) {
                return;
            }

            activeAuthorization = null;
            applySession(response.data, true, generation);
        } catch {
            uploadError.value =
                'This episode could not be skipped safely. Retry or resolve its retained state.';
        }
    }

    function clearProcessingPoll(): void {
        if (processingPollTimer !== null) {
            window.clearTimeout(processingPollTimer);
            processingPollTimer = null;
        }
    }

    function resetStorageState(): void {
        storageRequestId += 1;
        previewRequest.cancel();
        admissionRequestId += 1;
        admissionRequest.cancel();
        storagePreview.value = null;
        storageError.value = '';
        selectedDiskId.value = '';
        storageContextKey = null;
        idempotencyKey = null;
        idempotencyContextKey = null;
        autoAdmissionAttemptedContext = null;
        fingerprintCache.clear();
        fingerprintProgress.value = { completed: 0, total: 0, filename: '' };
        admissionStarted.value = false;
    }

    function setCategory(value: 'tv' | 'anime'): void {
        if (category.value === value || admissionStarted.value) {
            return;
        }

        const restartLookup = isLookingUp.value;
        cancelLookups();
        cancelConfirmation();
        cancelSeasonHydrations();
        category.value = value;

        if (restartLookup) {
            if (lastLookupKind === 'suggestion') {
                void loadFilenameSuggestions(sourceName.value, sourceRevision);
            } else {
                void runSmartSearch();
            }
        }
    }

    function changeShow(): void {
        if (admissionStarted.value) {
            return;
        }

        cancelConfirmation();
        currentStep.value = 2;
        statusMessage.value =
            'Show identification reopened. The current episode review is retained until another show is confirmed.';
    }

    function refreshReviewValidation(): void {
        const episodeCounts = new Map<number, number>();

        reviewMappings.value.forEach((mapping) => {
            if (mapping.seriesEpisodeId !== null) {
                episodeCounts.set(
                    mapping.seriesEpisodeId,
                    (episodeCounts.get(mapping.seriesEpisodeId) ?? 0) + 1,
                );
            }
        });

        reviewMappings.value.forEach((mapping) => {
            const episode = episodeForMapping(mapping);

            if (!episode) {
                mapping.validationStatus = 'needs_assignment';

                return;
            }

            if ((episodeCounts.get(episode.id) ?? 0) > 1) {
                mapping.validationStatus = 'conflict';

                return;
            }

            if (
                episode.has_current_primary &&
                (!episode.can_replace_current_primary ||
                    mapping.replacesMediaFileId !==
                        episode.current_primary?.id ||
                    !mapping.replacementConfirmed)
            ) {
                mapping.validationStatus = 'replacement_required';

                return;
            }

            mapping.validationStatus =
                mapping.assignmentOrigin === 'auto' ? 'auto' : 'edited';
        });
    }

    function isSeasonHydrated(seasonNumber: number): boolean {
        return (
            confirmedSeries.value?.available_seasons.find(
                (season) => season.season_number === seasonNumber,
            )?.hydrated ?? false
        );
    }

    function episodesForSeason(
        seasonNumber: number | null,
    ): ConfirmedSeriesEpisode[] {
        if (seasonNumber === null) {
            return [];
        }

        return (
            confirmedSeries.value?.seasons.find(
                (season) => season.season_number === seasonNumber,
            )?.episodes ?? []
        );
    }

    function mappingBySourceKey(
        sourceKey: string,
    ): EpisodeReviewMapping | undefined {
        return reviewMappings.value.find(
            (mapping) => mapping.sourceKey === sourceKey,
        );
    }

    function episodeForMapping(
        mapping: EpisodeReviewMapping,
    ): ConfirmedSeriesEpisode | null {
        return mapping.seriesEpisodeId === null
            ? null
            : episodeByIdValue(mapping.seriesEpisodeId);
    }

    function episodeByIdValue(
        episodeId: number,
    ): ConfirmedSeriesEpisode | null {
        const series = confirmedSeries.value;

        return series ? episodeById(series, episodeId) : null;
    }

    function cancelLookups(): number {
        lookupRequestId += 1;
        textLookup.cancel();
        filenameLookup.cancel();
        detailsLookup.cancel();

        return lookupRequestId;
    }

    function cancelConfirmation(): void {
        confirmationRequestId += 1;
        confirmation.cancel();
    }

    function cancelSeasonHydrations(): void {
        pendingSeasonHydrations.forEach(({ request }) => request.cancel());
        pendingSeasonHydrations.clear();
        seasonHydrationStates.value = {};
        seasonHydrationErrors.value = {};
    }

    function lookupOptions(requestId: number) {
        return {
            onHttpException: () => {
                if (requestId === lookupRequestId) {
                    lookupError.value = 'Show lookup failed. Please try again.';
                }
            },
            onNetworkError: () => {
                if (requestId === lookupRequestId) {
                    lookupError.value =
                        'Show lookup failed. Check your connection and try again.';
                }
            },
        };
    }

    function isCurrentLookup(
        requestId: number,
        revision: number,
        requestedCategory: 'tv' | 'anime',
        query: string,
    ): boolean {
        return (
            requestId === lookupRequestId &&
            revision === sourceRevision &&
            category.value === requestedCategory &&
            searchInput.value === query
        );
    }

    function bumpRowSelectionRevision(sourceKey: string): void {
        rowSelectionRevisions.set(
            sourceKey,
            (rowSelectionRevisions.get(sourceKey) ?? 0) + 1,
        );
    }

    function interruptActiveTransfer(): void {
        if (activeTusUpload) {
            void activeTusUpload.abort(false);
        }
    }

    function handleOffline(): void {
        if (activeTusUpload) {
            connectionState.value = 'offline';
            speedBytesPerSecond.value = 0;
            etaSeconds.value = null;
            statusMessage.value =
                'Connection lost. Uploaded episode chunks remain safely staged.';
        }
    }

    function handleOnline(): void {
        if (activeTusUpload && connectionState.value === 'offline') {
            connectionState.value = 'retrying';
            statusMessage.value =
                'Connection restored. Retrying the current episode.';
        }
    }

    onMounted(() => {
        window.addEventListener('offline', handleOffline);
        window.addEventListener('online', handleOnline);
        window.addEventListener('beforeunload', interruptActiveTransfer);
        void loadResumableBatches();
    });

    onBeforeUnmount(() => {
        interruptActiveTransfer();
        activeTusUpload = null;
        cancelLookups();
        cancelConfirmation();
        cancelSeasonHydrations();
        storageRequestId += 1;
        previewRequest.cancel();
        admissionRequestId += 1;
        admissionRequest.cancel();
        authorizationRequest.cancel();
        statusRequest.cancel();
        pauseRequest.cancel();
        retryRequest.cancel();
        cancelRequest.cancel();
        resumableRequest.cancel();
        recoveryRequest.cancel();
        batchRequest.cancel();
        clearProcessingPoll();
        window.removeEventListener('offline', handleOffline);
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('beforeunload', interruptActiveTransfer);
    });

    return {
        currentStep,
        selectedFiles,
        acceptedFiles,
        issues,
        blockingIssues,
        excludedIssues,
        seasonCount,
        seasonNumbers,
        canContinue,
        sourceName,
        searchInput,
        results,
        parsedSource,
        selectedSeries,
        confirmedSeries,
        category,
        lookupError,
        lookupCompleted,
        isLookingUp,
        isConfirming,
        canKeepCurrentVideos,
        reviewMappings,
        reviewedMappings,
        reviewRows,
        reviewGroups,
        reviewCounts,
        isReviewReady,
        reviewConfirmed,
        storagePreview,
        storageError,
        selectedDiskId,
        isStorageLoading,
        isFingerprinting,
        fingerprintProgress,
        isAdmitting,
        admissionStarted,
        admittedBatch,
        queueItems,
        activeQueueItem,
        connectionState,
        speedBytesPerSecond,
        etaSeconds,
        uploadError,
        overallConfirmedBytes,
        completedCount,
        skippedCount,
        resolvedCount,
        resumableBatches,
        recoveryError,
        isRecovering,
        seasonHydrationStates,
        seasonHydrationErrors,
        statusMessage,
        selectSources,
        goToSource,
        keepCurrentVideos,
        runSmartSearch,
        selectSeries,
        confirmSeries,
        setCategory,
        changeShow,
        setReviewSeason,
        setReviewEpisode,
        setReplacementConfirmed,
        previewBulkAssignment,
        applyBulkAssignment,
        hydrateSeason,
        confirmEpisodeReview,
        returnToReview,
        requestStoragePreview,
        retryStorage,
        prepareBatch,
        recoverBatch,
        pauseUpload,
        retryUpload,
        retryValidation,
        retryBatchReconciliation,
        skipCurrentEpisode,
        uploadMoreEpisodes,
        isSeasonHydrated,
        episodesForSeason,
    };
}

function createSeasonHydrationRequest() {
    return useHttp<Record<string, never>, SeriesConfirmationResponse>({});
}

function readHttpError(data: string | undefined, fallback: string): string {
    if (!data) {
        return fallback;
    }

    try {
        const payload = JSON.parse(data) as { message?: string };

        return payload.message ?? fallback;
    } catch {
        return fallback;
    }
}

function episodeById(
    series: ConfirmedSeries,
    episodeId: number,
): ConfirmedSeriesEpisode | null {
    for (const season of series.seasons) {
        const episode = season.episodes.find(
            (candidate) => candidate.id === episodeId,
        );

        if (episode) {
            return episode;
        }
    }

    return null;
}

function episodeByNumbers(
    series: ConfirmedSeries,
    seasonNumber: number,
    episodeNumber: number,
): ConfirmedSeriesEpisode | null {
    return (
        series.seasons
            .find((season) => season.season_number === seasonNumber)
            ?.episodes.find(
                (episode) => episode.episode_number === episodeNumber,
            ) ?? null
    );
}

function seasonNumberForEpisode(
    series: ConfirmedSeries,
    episodeId: number,
): number | null {
    return (
        series.seasons.find((season) =>
            season.episodes.some((episode) => episode.id === episodeId),
        )?.season_number ?? null
    );
}

function reviewGroupFor(source: AcceptedEpisodeFile): { key: string } {
    const pathParts = source.relativePath.split('/');
    const directory = pathParts.slice(0, -1).slice(-2).join(' / ');

    if (directory) {
        return { key: `folder:${directory}` };
    }

    if (source.hint) {
        return { key: `season:${source.hint.seasonNumber}` };
    }

    return { key: 'attention' };
}

function reviewGroupLabel(key: string, firstRow: EpisodeReviewRow): string {
    if (key.startsWith('folder:')) {
        return key.slice('folder:'.length);
    }

    if (key.startsWith('season:') && firstRow.hint) {
        return seasonLabel(firstRow.hint.seasonNumber);
    }

    return 'Files needing attention';
}

function isReadyStatus(status: EpisodeReviewValidationStatus): boolean {
    return status === 'auto' || status === 'edited';
}

function seasonLabel(seasonNumber: number): string {
    return seasonNumber === 0 ? 'Specials' : `Season ${seasonNumber}`;
}

function suggestionSourceName(acceptedFiles: AcceptedEpisodeFile[]): string {
    const firstEpisode = acceptedFiles[0];

    if (!firstEpisode) {
        return '';
    }

    const rootFolders = acceptedFiles
        .map(({ relativePath }) => relativePath.split('/')[0])
        .filter((folder) => folder.length > 0);
    const rootFolder = rootFolders[0];
    const allShareRoot =
        rootFolders.length === acceptedFiles.length &&
        rootFolders.every((folder) => folder === rootFolder);
    const genericFolder =
        /^(?:season|series|shows?|tv(?:[ ._-]*shows?)?|anime|episodes?|media|videos?|library)(?:[ ._-]*\d{1,4})?$/iu;

    if (allShareRoot && rootFolder && !genericFolder.test(rootFolder)) {
        return rootFolder;
    }

    return firstEpisode.filename;
}
