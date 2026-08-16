<script setup lang="ts">
import { Head, router, useForm, useHttp, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    CheckCircle2,
    ChevronDown,
    Film,
    FolderSearch,
    HardDrive,
    ImageOff,
    LoaderCircle,
    RefreshCw,
    Search,
    Trash2,
    Tv2,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import {
    destroy as destroyFinding,
    identifyAndImport,
    previewIdentity,
    queueImport,
    restore,
} from '@/actions/App/Http/Controllers/LibraryFindingController';
import MissingMediaFileController from '@/actions/App/Http/Controllers/MissingMediaFileController';
import * as MovieController from '@/actions/App/Http/Controllers/MovieController';
import {
    search as searchShows,
    season as showSeason,
    show as showDetails,
} from '@/actions/App/Http/Controllers/Series/SeriesLookupController';
import IdentifyMovieStep from '@/components/movie-upload/IdentifyMovieStep.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { index as scanIndex, store as startScan } from '@/routes/library_scans';
import type {
    IdentityPreview,
    IdentityPreviewResponse,
    LibraryHistoryItem,
    LibraryScanProgress,
    LibraryScanSummary,
    LibraryTask,
    MaintenanceWarning,
    TmdbShowSeasonResponse,
    UnavailableDisk,
} from '@/types/library-scan';
import type {
    DetailsResponse,
    MovieSummary,
    SearchResponse,
} from '@/types/movie-upload';
import type { SeriesLookupResponse, SeriesSearchResult } from '@/types/series';

type ShowDetailsResponse = {
    data: SeriesSearchResult & {
        seasons: Array<{
            season_number: number;
            name: string;
            episode_count: number;
        }>;
    };
};

const props = defineProps<{
    scan: LibraryScanSummary | null;
    tasks: LibraryTask[];
    remaining_count: number;
    processing_count: number;
    progress: LibraryScanProgress;
    history: LibraryHistoryItem[];
    maintenance_warning: MaintenanceWarning | null;
    unavailable: UnavailableDisk[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Library scan', href: scanIndex() }] },
});

const queuePropNames = [
    'scan',
    'tasks',
    'remaining_count',
    'processing_count',
    'progress',
    'history',
    'maintenance_warning',
    'unavailable',
];
const scanForm = useForm({});
const actionForm = useForm({ finding: '' });
const hiddenTaskIds = ref(new Set<number>());
const activeTaskId = ref<number | null>(null);
const lastQueuePosition = ref(0);
const taskCard = ref<HTMLElement | null>(null);
const identifyTarget = ref<LibraryTask | null>(null);
const identifyOpen = ref(false);
const searchInput = ref('');
const movieResults = ref<MovieSummary[]>([]);
const selectedMovie = ref<MovieSummary | null>(null);
const showResults = ref<SeriesSearchResult[]>([]);
const highlightedShow = ref<SeriesSearchResult | null>(null);
const selectedShow = ref<ShowDetailsResponse['data'] | null>(null);
const showOverviewCharacterLimit = 80;
const showCategory = ref<'tv' | 'anime'>('tv');
const selectedSeasonNumber = ref<number | null>(null);
const selectedEpisodeNumber = ref<number | null>(null);
const seasonEpisodes = ref<TmdbShowSeasonResponse['data']['episodes']>([]);
const identityPreview = ref<IdentityPreview | null>(null);
const lookupCompleted = ref(false);
const lookupError = ref('');
const identifyGeneration = ref(0);
const movieTextLookup = useHttp<{ query: string }, SearchResponse>({
    query: '',
});
const movieDetailsLookup = useHttp<Record<string, never>, DetailsResponse>({});
const showTextLookup = useHttp<{ query: string }, SeriesLookupResponse>({
    query: '',
});
const showDetailsLookup = useHttp<Record<string, never>, ShowDetailsResponse>(
    {},
);
const seasonLookup = useHttp<Record<string, never>, TmdbShowSeasonResponse>({});
const previewLookup = useHttp<
    {
        tmdb_id: number;
        category: 'tv' | 'anime' | null;
        season_number: number | null;
        episode_number: number | null;
    },
    IdentityPreviewResponse
>({
    tmdb_id: 0,
    category: null,
    season_number: null,
    episode_number: null,
});
const identifyForm = useForm({
    tmdb_id: 0,
    category: null as 'tv' | 'anime' | null,
    season_number: null as number | null,
    episode_number: null as number | null,
    destination_relative_path: '',
});
const deleteTarget = ref<LibraryTask | null>(null);
const deleteOpen = ref(false);
const deleteForm = useForm({ deletion_confirmed: true });
const missingTarget = ref<LibraryTask | null>(null);
const missingOpen = ref(false);
const missingForm = useForm({ removal_confirmed: true });

const visibleTasks = computed(() =>
    props.tasks.filter((task) => !hiddenTaskIds.value.has(task.id)),
);
const activeTask = computed(() => {
    const selected = visibleTasks.value.find(
        (task) => task.id === activeTaskId.value,
    );

    return (
        selected ??
        visibleTasks.value[
            Math.min(lastQueuePosition.value, visibleTasks.value.length - 1)
        ] ??
        null
    );
});
const activeIndex = computed(() =>
    activeTask.value === null
        ? -1
        : visibleTasks.value.findIndex(
              (task) => task.id === activeTask.value?.id,
          ),
);
const progressPercent = computed(() =>
    props.progress.total === 0
        ? 100
        : Math.round((props.progress.completed / props.progress.total) * 100),
);
const waitingForQueue = computed(
    () => hiddenTaskIds.value.size > 0 || props.processing_count > 0,
);
const isLookingUp = computed(
    () =>
        movieTextLookup.processing ||
        movieDetailsLookup.processing ||
        showTextLookup.processing ||
        showDetailsLookup.processing ||
        seasonLookup.processing ||
        previewLookup.processing,
);

function reconcileQueue(): void {
    const tasksById = new Map(props.tasks.map((task) => [task.id, task]));
    hiddenTaskIds.value = new Set(
        [...hiddenTaskIds.value].filter((id) => {
            const task = tasksById.get(id);

            return task !== undefined && task.status !== 'failed';
        }),
    );
    activeTaskId.value = activeTask.value?.id ?? null;
}

const { start: startQueuePolling, stop: stopQueuePolling } = usePoll(
    2000,
    { only: queuePropNames, onSuccess: reconcileQueue },
    { mode: 'rest' },
);

watch(
    () => [props.tasks, props.processing_count, props.progress.completed],
    reconcileQueue,
    { deep: true, immediate: true },
);

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return 'Unknown size';
    }

    const divisor = bytes >= 1_073_741_824 ? 1_073_741_824 : 1_048_576;

    return new Intl.NumberFormat(undefined, {
        style: 'unit',
        unit: bytes >= 1_073_741_824 ? 'gigabyte' : 'megabyte',
        unitDisplay: 'short',
        maximumFractionDigits: 2,
    }).format(bytes / divisor);
}

function formatTime(value: string | null): string {
    return value === null
        ? 'Unknown time'
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value));
}

function taskLabel(task: LibraryTask): string {
    const subject = task.media_type === 'show' ? 'Show episode' : 'Movie';

    return {
        identify: `Identify ${subject.toLowerCase()}`,
        import: 'Ready to import',
        restore: 'Verified moved file',
        retry_import: 'Import failed',
        retry_restore: 'Restore failed',
        retry_delete: 'Deletion failed',
        missing: 'Tracked file missing',
    }[task.task_type];
}

function taskIdentity(task: LibraryTask): string | null {
    if (task.media_type === 'movie') {
        if (!task.movie.title) {
            return null;
        }

        return `${task.movie.title}${task.movie.release_year ? ` (${task.movie.release_year})` : ''}`;
    }

    const identity =
        task.show.season_number !== null && task.show.episode_number !== null
            ? `S${String(task.show.season_number).padStart(2, '0')}E${String(task.show.episode_number).padStart(2, '0')}`
            : null;

    return [task.show.name, identity, task.show.episode_name]
        .filter(Boolean)
        .join(' · ');
}

function startLibraryScan(): void {
    scanForm.submit(startScan());
}

function selectTask(index: number): void {
    const task = visibleTasks.value[index];

    if (!task) {
        return;
    }

    lastQueuePosition.value = index;
    activeTaskId.value = task.id;
    actionForm.clearErrors();
    void nextTick(() => taskCard.value?.focus());
}

function hideTaskAndAdvance(taskId: number): void {
    lastQueuePosition.value = Math.max(activeIndex.value, 0);
    hiddenTaskIds.value = new Set([...hiddenTaskIds.value, taskId]);
    activeTaskId.value =
        visibleTasks.value[
            Math.min(lastQueuePosition.value, visibleTasks.value.length - 1)
        ]?.id ?? null;
    void nextTick(() => taskCard.value?.focus());
    stopQueuePolling();
    router.reload({
        only: queuePropNames,
        preserveErrors: true,
        onSuccess: reconcileQueue,
        onFinish: startQueuePolling,
    });
}

function queueCurrentImport(task: LibraryTask): void {
    actionForm.submit(queueImport(task.id), {
        preserveScroll: true,
        onSuccess: () => hideTaskAndAdvance(task.id),
    });
}

function queueCurrentRestore(task: LibraryTask): void {
    actionForm.submit(restore(task.id), {
        preserveScroll: true,
        onSuccess: () => hideTaskAndAdvance(task.id),
    });
}

function resetIdentityState(): void {
    identifyGeneration.value += 1;
    movieResults.value = [];
    selectedMovie.value = null;
    showResults.value = [];
    highlightedShow.value = null;
    selectedShow.value = null;
    seasonEpisodes.value = [];
    selectedSeasonNumber.value = null;
    selectedEpisodeNumber.value = null;
    identityPreview.value = null;
    lookupCompleted.value = false;
    lookupError.value = '';
    identifyForm.reset();
    identifyForm.clearErrors();
}

function limitShowOverview(overview: string | null): string {
    const trimmedOverview = overview?.trim();

    if (!trimmedOverview) {
        return 'No overview is available.';
    }

    const characters = Array.from(trimmedOverview);

    if (characters.length <= showOverviewCharacterLimit) {
        return trimmedOverview;
    }

    return `${characters
        .slice(0, showOverviewCharacterLimit - 1)
        .join('')
        .trimEnd()}…`;
}

function openIdentify(task: LibraryTask): void {
    identifyTarget.value = task;
    resetIdentityState();
    searchInput.value =
        task.media_type === 'show'
            ? (task.show.name ?? task.show.search_query)
            : (taskIdentity(task) ?? task.source_filename);

    if (task.media_type === 'show') {
        showCategory.value = task.show.category ?? 'tv';
        selectedSeasonNumber.value = task.show.season_number;
        selectedEpisodeNumber.value = task.show.episode_number;
    }

    identifyOpen.value = true;
}

function readHttpError(data: string | undefined, fallback: string): string {
    if (!data) {
        return fallback;
    }

    try {
        const payload = JSON.parse(data) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        return (
            payload.errors?.tmdb_id?.[0] ??
            payload.errors?.season_number?.[0] ??
            payload.errors?.episode_number?.[0] ??
            payload.errors?.finding?.[0] ??
            payload.message ??
            fallback
        );
    } catch {
        return fallback;
    }
}

async function searchIdentity(): Promise<void> {
    const target = identifyTarget.value;
    const query = searchInput.value.normalize('NFC').trim();

    if (!target || !query) {
        return;
    }

    const generation = ++identifyGeneration.value;
    lookupError.value = '';
    lookupCompleted.value = false;
    identityPreview.value = null;

    try {
        if (target.media_type === 'movie') {
            selectedMovie.value = null;
            movieResults.value = [];

            if (/^tt\d{7,12}$/i.test(query)) {
                const response = await movieDetailsLookup.get(
                    MovieController.showImdb.url(query.toLowerCase()),
                );

                if (generation !== identifyGeneration.value) {
                    return;
                }

                movieResults.value = [response.data];
            } else if (/^\d+$/.test(query)) {
                const response = await movieDetailsLookup.get(
                    MovieController.showTmdb.url(Number(query)),
                );

                if (generation !== identifyGeneration.value) {
                    return;
                }

                movieResults.value = [response.data];
            } else {
                movieTextLookup.query = query;
                const response = await movieTextLookup.get(
                    MovieController.search.url(),
                );

                if (generation !== identifyGeneration.value) {
                    return;
                }

                movieResults.value = response.data;
            }
        } else {
            highlightedShow.value = null;
            selectedShow.value = null;
            showResults.value = [];

            if (/^\d+$/.test(query)) {
                const response = await showDetailsLookup.get(
                    showDetails.url(Number(query)),
                );

                if (generation !== identifyGeneration.value) {
                    return;
                }

                showResults.value = [response.data];
            } else {
                showTextLookup.query = query;
                const response = await showTextLookup.get(searchShows.url());

                if (generation !== identifyGeneration.value) {
                    return;
                }

                showResults.value = response.data;
            }
        }

        lookupCompleted.value = true;
    } catch {
        if (generation !== identifyGeneration.value) {
            return;
        }

        lookupCompleted.value = true;
        lookupError.value = `${target.media_type === 'show' ? 'Show' : 'Movie'} lookup failed. Please try again.`;
    }
}

async function selectShow(result: SeriesSearchResult): Promise<void> {
    const targetId = identifyTarget.value?.id;
    const generation = ++identifyGeneration.value;
    lookupError.value = '';

    try {
        const response = await showDetailsLookup.get(
            showDetails.url(result.tmdb_id),
        );

        if (
            generation !== identifyGeneration.value ||
            identifyTarget.value?.id !== targetId
        ) {
            return;
        }

        selectedShow.value = response.data;
        const hintedSeason =
            identifyTarget.value?.media_type === 'show'
                ? identifyTarget.value.show.season_number
                : null;
        selectedSeasonNumber.value = response.data.seasons.some(
            (season) => season.season_number === hintedSeason,
        )
            ? hintedSeason
            : (response.data.seasons[0]?.season_number ?? null);
        await hydrateSelectedSeason(generation, targetId);
    } catch {
        if (generation !== identifyGeneration.value) {
            return;
        }

        lookupError.value = 'Show details could not be loaded.';
    }
}

async function hydrateSelectedSeason(
    expectedGeneration = ++identifyGeneration.value,
    targetId = identifyTarget.value?.id,
): Promise<void> {
    if (!selectedShow.value || selectedSeasonNumber.value === null) {
        return;
    }

    const tmdbId = selectedShow.value.tmdb_id;
    const seasonNumber = selectedSeasonNumber.value;
    seasonEpisodes.value = [];
    identityPreview.value = null;

    try {
        const response = await seasonLookup.get(
            showSeason.url({ tmdbId, seasonNumber }),
        );

        if (
            expectedGeneration !== identifyGeneration.value ||
            identifyTarget.value?.id !== targetId ||
            selectedShow.value?.tmdb_id !== tmdbId ||
            selectedSeasonNumber.value !== seasonNumber
        ) {
            return;
        }

        seasonEpisodes.value = response.data.episodes;
        const hint =
            identifyTarget.value?.media_type === 'show'
                ? identifyTarget.value.show.episode_number
                : null;
        selectedEpisodeNumber.value = response.data.episodes.some(
            (episode) => episode.episode_number === hint,
        )
            ? hint
            : (response.data.episodes[0]?.episode_number ?? null);
    } catch {
        if (expectedGeneration !== identifyGeneration.value) {
            return;
        }

        lookupError.value = 'Season episodes could not be loaded.';
    }
}

function changeSeason(event: Event): void {
    selectedSeasonNumber.value = Number(
        (event.target as HTMLSelectElement).value,
    );
    selectedEpisodeNumber.value = null;
    void hydrateSelectedSeason();
}

async function previewSelectedIdentity(
    importImmediately = false,
): Promise<void> {
    const target = identifyTarget.value;

    if (!target) {
        return;
    }

    const tmdbId =
        target.media_type === 'movie'
            ? selectedMovie.value?.tmdb_id
            : selectedShow.value?.tmdb_id;

    if (!tmdbId) {
        return;
    }

    const generation = identifyGeneration.value;
    const targetId = target.id;
    lookupError.value = '';
    previewLookup.tmdb_id = tmdbId;
    previewLookup.category =
        target.media_type === 'show' ? showCategory.value : null;
    previewLookup.season_number =
        target.media_type === 'show' ? selectedSeasonNumber.value : null;
    previewLookup.episode_number =
        target.media_type === 'show' ? selectedEpisodeNumber.value : null;

    try {
        const response = await previewLookup.get(
            previewIdentity.url(target.id),
            {
                onHttpException: (exception) => {
                    lookupError.value = readHttpError(
                        exception.data,
                        importImmediately
                            ? 'Import could not be prepared.'
                            : 'Identity preview failed.',
                    );
                },
            },
        );

        if (
            generation !== identifyGeneration.value ||
            identifyTarget.value?.id !== targetId
        ) {
            return;
        }

        if (importImmediately) {
            if (response.data.blocker) {
                lookupError.value = response.data.blocker.message;

                return;
            }

            submitIdentityAndImport(target, response.data);

            return;
        }

        identityPreview.value = response.data;
    } catch {
        if (generation !== identifyGeneration.value) {
            return;
        }

        lookupError.value ||= importImmediately
            ? 'Import could not be prepared. Please try again.'
            : 'Identity preview failed. Please try again.';
    }
}

function submitIdentityAndImport(
    target: LibraryTask,
    preview: IdentityPreview,
): void {
    if (preview.blocker) {
        return;
    }

    identifyForm.tmdb_id =
        'show' in preview ? preview.show.tmdb_id : preview.movie.tmdb_id;
    identifyForm.category = 'show' in preview ? preview.show.category : null;
    identifyForm.season_number =
        'show' in preview ? preview.show.season_number : null;
    identifyForm.episode_number =
        'show' in preview ? preview.show.episode_number : null;
    identifyForm.destination_relative_path = preview.destination.relative_path;
    identifyForm.submit(identifyAndImport(target.id), {
        preserveScroll: true,
        onError: (errors) => {
            lookupError.value =
                errors.tmdb_id ??
                errors.category ??
                errors.season_number ??
                errors.episode_number ??
                errors.destination_relative_path ??
                'Import could not be started.';
        },
        onSuccess: () => {
            identifyOpen.value = false;
            hideTaskAndAdvance(target.id);
        },
    });
}

function confirmIdentityAndImport(): void {
    const target = identifyTarget.value;
    const preview = identityPreview.value;

    if (!target || !preview) {
        return;
    }

    submitIdentityAndImport(target, preview);
}

function chooseAnotherIdentity(): void {
    identityPreview.value = null;
    selectedMovie.value = null;
    highlightedShow.value = null;
    selectedShow.value = null;
    seasonEpisodes.value = [];
    identifyForm.clearErrors();
}

function openDelete(task: LibraryTask): void {
    deleteTarget.value = task;
    deleteForm.reset();
    deleteForm.clearErrors();
    deleteOpen.value = true;
}

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    const taskId = deleteTarget.value.id;
    deleteForm.submit(destroyFinding(taskId), {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
            hideTaskAndAdvance(taskId);
        },
    });
}

function openMissing(task: LibraryTask): void {
    missingTarget.value = task;
    missingForm.reset();
    missingForm.clearErrors();
    missingOpen.value = true;
}

function confirmMissing(): void {
    if (!missingTarget.value) {
        return;
    }

    const taskId = missingTarget.value.id;
    missingForm.submit(MissingMediaFileController(taskId), {
        preserveScroll: true,
        onSuccess: () => {
            missingOpen.value = false;
            hideTaskAndAdvance(taskId);
        },
    });
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <Head title="Library scan" />

        <header
            class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <FolderSearch class="size-5" />
                </span>
                <div>
                    <h1
                        class="text-xl font-semibold tracking-tight md:text-2xl"
                    >
                        Library tasks
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Movies and Shows share one prioritized review queue.
                    </p>
                </div>
            </div>
            <Button
                :disabled="
                    scanForm.processing ||
                    scan?.status === 'queued' ||
                    scan?.status === 'scanning'
                "
                @click="startLibraryScan"
            >
                <LoaderCircle
                    v-if="
                        scanForm.processing ||
                        scan?.status === 'queued' ||
                        scan?.status === 'scanning'
                    "
                    class="size-4 motion-safe:animate-spin"
                />
                <RefreshCw v-else class="size-4" />
                {{ scan?.status === 'scanning' ? 'Scanning…' : 'Scan now' }}
            </Button>
        </header>

        <section
            class="grid gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:grid-cols-[1fr_auto] sm:items-center"
            aria-label="Task progress"
        >
            <div class="grid gap-2">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-medium">
                        {{ visibleTasks.length }} remaining
                        <span
                            v-if="processing_count"
                            class="font-normal text-muted-foreground"
                        >
                            · {{ processing_count }} processing
                        </span>
                    </span>
                    <span class="text-muted-foreground">
                        {{ progress.completed }}/{{ progress.total }} completed
                    </span>
                </div>
                <div
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="progressPercent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                        :style="{ width: `${progressPercent}%` }"
                    />
                </div>
            </div>
            <Badge v-if="scan" variant="outline" class="w-fit capitalize">
                {{ scan.status }}
            </Badge>
        </section>

        <div
            v-if="unavailable.length"
            class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
            role="status"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
            <p>
                <strong
                    >{{ unavailable.length }} unavailable root<span
                        v-if="unavailable.length !== 1"
                        >s</span
                    >.</strong
                >
                Missing checks were skipped for
                {{
                    unavailable
                        .map(
                            (root) =>
                                `${root.label} (${root.root_kind === 'series' ? 'Shows' : 'Movies'})`,
                        )
                        .join(', ')
                }}.
            </p>
        </div>

        <div
            v-if="maintenance_warning"
            class="flex items-start gap-3 rounded-xl border bg-muted/30 p-4 text-sm"
            role="status"
        >
            <AlertTriangle
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <p>
                <strong>Folder maintenance needs attention.</strong>
                {{ maintenance_warning.message }} The next scan will retry it.
            </p>
        </div>

        <section v-if="activeTask" class="grid gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    Task {{ activeIndex + 1 }} of {{ visibleTasks.length }}
                </p>
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="activeIndex <= 0"
                        aria-label="Previous task"
                        @click="selectTask(activeIndex - 1)"
                    >
                        <ArrowLeft class="size-4" /> Previous
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="activeIndex >= visibleTasks.length - 1"
                        aria-label="Next task"
                        @click="selectTask(activeIndex + 1)"
                    >
                        Next <ArrowRight class="size-4" />
                    </Button>
                </div>
            </div>

            <article
                ref="taskCard"
                tabindex="-1"
                class="grid gap-5 rounded-2xl border bg-card p-5 shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring md:p-6"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div class="min-w-0">
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    activeTask.media_type === 'show'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                <Tv2
                                    v-if="activeTask.media_type === 'show'"
                                    class="size-3"
                                />
                                <Film v-else class="size-3" />
                                {{
                                    activeTask.media_type === 'show'
                                        ? 'Show'
                                        : 'Movie'
                                }}
                            </Badge>
                            <Badge variant="outline">{{
                                taskLabel(activeTask)
                            }}</Badge>
                        </div>
                        <h2 class="truncate text-xl font-semibold">
                            {{ activeTask.source_filename }}
                        </h2>
                    </div>
                    <p class="text-sm font-medium text-muted-foreground">
                        {{ formatBytes(activeTask.size_bytes) }}
                    </p>
                </header>

                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div class="grid gap-1 sm:col-span-2">
                        <dt
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Source
                        </dt>
                        <dd class="flex items-start gap-2 break-all">
                            <HardDrive class="mt-0.5 size-4 shrink-0" />
                            {{ activeTask.disk_id }}:/{{
                                activeTask.relative_path
                            }}
                        </dd>
                    </div>
                    <div v-if="taskIdentity(activeTask)" class="grid gap-1">
                        <dt
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            {{
                                activeTask.media_type === 'show'
                                    ? 'Show episode'
                                    : 'Movie'
                            }}
                        </dt>
                        <dd class="font-medium">
                            {{ taskIdentity(activeTask) }}
                        </dd>
                    </div>
                    <div v-if="activeTask.tmdb_id" class="grid gap-1">
                        <dt
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            TMDB
                        </dt>
                        <dd>{{ activeTask.tmdb_id }}</dd>
                    </div>
                    <div
                        v-if="activeTask.tracked_source"
                        class="grid gap-1 sm:col-span-2"
                    >
                        <dt
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Missing tracked path
                        </dt>
                        <dd class="break-all">
                            {{ activeTask.tracked_source.disk_id }}:/{{
                                activeTask.tracked_source.relative_path
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="activeTask.destination_relative_path"
                        class="grid gap-1 sm:col-span-2"
                    >
                        <dt
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Destination
                        </dt>
                        <dd class="break-all">
                            {{ activeTask.disk_id }}:/{{
                                activeTask.destination_relative_path
                            }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="activeTask.error_detail"
                    role="alert"
                    class="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                >
                    {{ activeTask.error_detail }}
                </p>
                <p
                    v-if="actionForm.errors.finding"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ actionForm.errors.finding }}
                </p>

                <footer class="flex flex-wrap justify-end gap-2 border-t pt-4">
                    <template v-if="activeTask.task_type === 'identify'">
                        <Button
                            variant="destructive"
                            @click="openDelete(activeTask)"
                        >
                            <Trash2 class="size-4" /> Delete file
                        </Button>
                        <Button @click="openIdentify(activeTask)">
                            <Search class="size-4" /> Choose identity
                        </Button>
                    </template>
                    <Button
                        v-else-if="activeTask.task_type === 'import'"
                        :disabled="actionForm.processing"
                        @click="queueCurrentImport(activeTask)"
                    >
                        <LoaderCircle
                            v-if="actionForm.processing"
                            class="size-4 motion-safe:animate-spin"
                        />
                        Import
                    </Button>
                    <Button
                        v-else-if="
                            activeTask.task_type === 'restore' ||
                            activeTask.task_type === 'retry_restore'
                        "
                        :disabled="actionForm.processing"
                        @click="queueCurrentRestore(activeTask)"
                    >
                        <RefreshCw
                            v-if="activeTask.task_type === 'retry_restore'"
                            class="size-4"
                        />
                        {{
                            activeTask.task_type === 'retry_restore'
                                ? 'Retry restore'
                                : 'Restore moved file'
                        }}
                    </Button>
                    <Button
                        v-else-if="activeTask.task_type === 'retry_import'"
                        :disabled="actionForm.processing"
                        @click="queueCurrentImport(activeTask)"
                    >
                        <RefreshCw class="size-4" /> Retry import
                    </Button>
                    <Button
                        v-else-if="activeTask.task_type === 'retry_delete'"
                        variant="destructive"
                        @click="openDelete(activeTask)"
                    >
                        <RefreshCw class="size-4" /> Retry deletion
                    </Button>
                    <Button
                        v-else-if="activeTask.task_type === 'missing'"
                        variant="destructive"
                        @click="openMissing(activeTask)"
                    >
                        Confirm removed externally
                    </Button>
                </footer>
            </article>
        </section>

        <section
            v-else-if="waitingForQueue"
            class="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-dashed bg-muted/10 p-8 text-center"
            aria-live="polite"
        >
            <LoaderCircle
                class="size-8 text-primary motion-safe:animate-spin"
            />
            <h2 class="mt-3 text-lg font-semibold">Work is in progress</h2>
            <p class="mt-1 max-w-md text-sm text-muted-foreground">
                Queued file operations will reappear only if they need another
                decision.
            </p>
        </section>

        <section
            v-else
            class="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-dashed bg-muted/10 p-8 text-center"
        >
            <CheckCircle2 class="size-9 text-emerald-600" />
            <h2 class="mt-3 text-xl font-semibold">All caught up</h2>
            <p class="mt-1 max-w-md text-sm text-muted-foreground">
                There are no unresolved Movie or Show tasks from the latest
                scan.
            </p>
        </section>

        <details v-if="history.length" class="group rounded-xl border bg-card">
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Recently completed
                <span
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    {{ history.length }}
                    <ChevronDown
                        class="size-4 transition group-open:rotate-180 motion-reduce:transition-none"
                    />
                </span>
            </summary>
            <ul class="divide-y border-t">
                <li
                    v-for="item in history"
                    :key="item.id"
                    class="grid gap-1 px-4 py-3 text-sm sm:grid-cols-[auto_1fr_auto] sm:items-center"
                >
                    <Badge variant="outline">{{
                        item.media_type === 'show' ? 'Show' : 'Movie'
                    }}</Badge>
                    <span class="truncate font-medium">{{ item.name }}</span>
                    <span class="text-xs text-muted-foreground sm:text-right">
                        <span class="capitalize">{{
                            (item.outcome ?? 'completed').replaceAll('_', ' ')
                        }}</span>
                        · {{ formatTime(item.completed_at) }}
                    </span>
                </li>
            </ul>
        </details>

        <Dialog v-model:open="identifyOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
                <template v-if="identifyTarget">
                    <template v-if="!identityPreview">
                        <IdentifyMovieStep
                            v-if="identifyTarget.media_type === 'movie'"
                            v-model:search-input="searchInput"
                            :source-filename="identifyTarget.source_filename"
                            :results="movieResults"
                            :selected-movie="selectedMovie"
                            :parsed-filename="null"
                            :is-looking-up="isLookingUp"
                            :is-confirming="previewLookup.processing"
                            :lookup-completed="lookupCompleted"
                            :error-message="lookupError"
                            step-label="Library finding"
                            heading="Choose movie"
                            @search="searchIdentity"
                            @select="selectedMovie = $event"
                            @confirm="previewSelectedIdentity"
                        />

                        <section v-else class="grid gap-5">
                            <DialogHeader>
                                <DialogTitle>Choose Show episode</DialogTitle>
                                <DialogDescription>
                                    Select a Show, category, season, and episode
                                    for
                                    {{ identifyTarget.source_filename }}.
                                </DialogDescription>
                            </DialogHeader>

                            <div
                                class="flex min-w-0 items-start gap-3 rounded-xl border bg-muted/30 p-3"
                            >
                                <FolderSearch
                                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0 text-sm">
                                    <p class="font-medium">Source location</p>
                                    <p class="break-all text-muted-foreground">
                                        {{ identifyTarget.relative_path }}
                                    </p>
                                    <p
                                        v-if="identifyTarget.source_folder"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Parent folders:
                                        {{ identifyTarget.source_folder }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="identifyTarget.show.category_required"
                                class="inline-flex w-fit rounded-lg border bg-muted/40 p-1"
                                role="radiogroup"
                                aria-label="Show category"
                            >
                                <Button
                                    size="sm"
                                    :variant="
                                        showCategory === 'tv'
                                            ? 'default'
                                            : 'ghost'
                                    "
                                    role="radio"
                                    :aria-checked="showCategory === 'tv'"
                                    @click="showCategory = 'tv'"
                                    >TV</Button
                                >
                                <Button
                                    size="sm"
                                    :variant="
                                        showCategory === 'anime'
                                            ? 'default'
                                            : 'ghost'
                                    "
                                    role="radio"
                                    :aria-checked="showCategory === 'anime'"
                                    @click="showCategory = 'anime'"
                                    >Anime</Button
                                >
                            </div>
                            <Badge v-else variant="outline" class="w-fit">
                                {{ showCategory === 'anime' ? 'Anime' : 'TV' }}
                                · existing category
                            </Badge>

                            <form
                                class="flex flex-col gap-2 sm:flex-row"
                                @submit.prevent="searchIdentity"
                            >
                                <Input
                                    v-model="searchInput"
                                    placeholder="Show title or TMDB ID"
                                    autocomplete="off"
                                    aria-label="Search by Show title or numeric TMDB ID"
                                />
                                <Button type="submit" :disabled="isLookingUp">
                                    <LoaderCircle
                                        v-if="isLookingUp"
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    <Search v-else class="size-4" /> Search
                                </Button>
                            </form>

                            <div
                                v-if="lookupError"
                                role="alert"
                                class="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                            >
                                {{ lookupError }}
                            </div>

                            <div
                                v-if="isLookingUp && !highlightedShow"
                                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                                aria-label="Loading Show results"
                                aria-live="polite"
                            >
                                <div
                                    v-for="index in 6"
                                    :key="index"
                                    class="flex gap-3 rounded-xl border bg-card p-3"
                                >
                                    <Skeleton
                                        class="h-28 w-20 shrink-0 rounded-lg"
                                    />
                                    <div
                                        class="flex flex-1 flex-col gap-2 py-1"
                                    >
                                        <Skeleton class="h-5 w-3/4" />
                                        <Skeleton class="h-4 w-1/3" />
                                        <Skeleton class="h-4 w-full" />
                                    </div>
                                </div>
                            </div>

                            <template
                                v-else-if="showResults.length && !selectedShow"
                            >
                                <div
                                    class="flex flex-wrap items-end justify-between gap-3"
                                >
                                    <h3 class="font-semibold">Matches</h3>
                                    <Badge variant="outline"
                                        >{{ showResults.length }} results</Badge
                                    >
                                </div>

                                <div
                                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                                    role="list"
                                >
                                    <article
                                        v-for="result in showResults"
                                        :key="result.tmdb_id"
                                        class="relative min-w-0 overflow-hidden rounded-xl border bg-card shadow-xs transition hover:border-primary/40 hover:shadow-sm motion-reduce:transition-none"
                                        :class="
                                            highlightedShow?.tmdb_id ===
                                            result.tmdb_id
                                                ? 'border-primary ring-2 ring-primary/20'
                                                : ''
                                        "
                                        role="listitem"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full gap-3 p-3 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                                            :aria-pressed="
                                                highlightedShow?.tmdb_id ===
                                                result.tmdb_id
                                            "
                                            :aria-label="`Choose ${result.name}${result.first_air_year ? ` (${result.first_air_year})` : ''}`"
                                            @click="highlightedShow = result"
                                        >
                                            <span
                                                class="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted transition motion-reduce:transition-none"
                                                :class="
                                                    highlightedShow?.tmdb_id ===
                                                    result.tmdb_id
                                                        ? 'opacity-40 blur-[1px]'
                                                        : ''
                                                "
                                            >
                                                <img
                                                    v-if="result.poster_url"
                                                    :src="result.poster_url"
                                                    :alt="`${result.name} poster`"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                />
                                                <ImageOff
                                                    v-else
                                                    class="size-6 text-muted-foreground"
                                                />
                                            </span>
                                            <span
                                                class="min-w-0 py-1 transition motion-reduce:transition-none"
                                                :class="
                                                    highlightedShow?.tmdb_id ===
                                                    result.tmdb_id
                                                        ? 'opacity-40 blur-[1px]'
                                                        : ''
                                                "
                                            >
                                                <span
                                                    class="block truncate font-medium"
                                                    >{{ result.name }}</span
                                                >
                                                <span
                                                    v-if="
                                                        result.original_name &&
                                                        result.original_name !==
                                                            result.name
                                                    "
                                                    class="block truncate text-xs text-muted-foreground"
                                                >
                                                    {{ result.original_name }}
                                                </span>
                                                <span
                                                    class="block text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        result.first_air_year ??
                                                        'Year unknown'
                                                    }}
                                                    · TMDB {{ result.tmdb_id }}
                                                </span>
                                                <span
                                                    class="mt-2 line-clamp-2 block text-xs leading-5 text-muted-foreground"
                                                >
                                                    {{
                                                        limitShowOverview(
                                                            result.overview,
                                                        )
                                                    }}
                                                </span>
                                            </span>
                                        </button>

                                        <Button
                                            v-if="
                                                highlightedShow?.tmdb_id ===
                                                result.tmdb_id
                                            "
                                            type="button"
                                            class="absolute inset-0 z-10 m-auto w-fit shadow-lg"
                                            :disabled="isLookingUp"
                                            :aria-label="`Select ${result.name} and continue`"
                                            @click="selectShow(result)"
                                        >
                                            <LoaderCircle
                                                v-if="isLookingUp"
                                                class="size-4 motion-safe:animate-spin"
                                            />
                                            <Check v-else class="size-4" />
                                            {{
                                                isLookingUp
                                                    ? 'Selecting…'
                                                    : 'Select'
                                            }}
                                        </Button>
                                    </article>
                                </div>
                            </template>

                            <div
                                v-else-if="
                                    lookupCompleted &&
                                    !selectedShow &&
                                    !lookupError
                                "
                                role="status"
                                class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-dashed bg-muted/20 p-8 text-center"
                            >
                                <Tv2
                                    class="size-8 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <h3 class="mt-3 font-medium">No Shows found</h3>
                                <p
                                    class="mt-1 max-w-md text-sm text-muted-foreground"
                                >
                                    No Shows matched “{{ searchInput.trim() }}”.
                                    Try the Show title without release tags or
                                    enter its numeric TMDB ID.
                                </p>
                            </div>

                            <div
                                v-if="selectedShow"
                                class="grid gap-4 rounded-xl border p-4 sm:grid-cols-[auto_1fr_1fr]"
                            >
                                <div
                                    class="flex h-32 w-22 items-center justify-center overflow-hidden rounded-lg bg-muted sm:row-span-2"
                                >
                                    <img
                                        v-if="selectedShow.poster_url"
                                        :src="selectedShow.poster_url"
                                        :alt="`${selectedShow.name} poster`"
                                        class="h-full w-full object-cover"
                                    />
                                    <ImageOff
                                        v-else
                                        class="size-6 text-muted-foreground"
                                    />
                                </div>
                                <div class="sm:col-span-2">
                                    <p class="font-semibold">
                                        {{ selectedShow.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            selectedShow.first_air_year ??
                                            'Year unknown'
                                        }}
                                        · TMDB {{ selectedShow.tmdb_id }}
                                    </p>
                                </div>
                                <label class="grid gap-1.5 text-sm">
                                    <span class="font-medium">Season</span>
                                    <select
                                        class="h-10 rounded-md border bg-background px-3"
                                        :value="selectedSeasonNumber ?? ''"
                                        @change="changeSeason"
                                    >
                                        <option
                                            v-for="season in selectedShow.seasons"
                                            :key="season.season_number"
                                            :value="season.season_number"
                                        >
                                            {{
                                                season.season_number === 0
                                                    ? 'Specials'
                                                    : season.name
                                            }}
                                        </option>
                                    </select>
                                </label>
                                <label class="grid gap-1.5 text-sm">
                                    <span class="font-medium">Episode</span>
                                    <select
                                        v-model.number="selectedEpisodeNumber"
                                        class="h-10 rounded-md border bg-background px-3"
                                        :disabled="
                                            seasonLookup.processing ||
                                            seasonEpisodes.length === 0
                                        "
                                    >
                                        <option
                                            v-for="episode in seasonEpisodes"
                                            :key="episode.tmdb_id"
                                            :value="episode.episode_number"
                                        >
                                            E{{
                                                String(
                                                    episode.episode_number,
                                                ).padStart(2, '0')
                                            }}
                                            · {{ episode.name }}
                                        </option>
                                    </select>
                                </label>
                            </div>

                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    @click="identifyOpen = false"
                                    >Cancel</Button
                                >
                                <Button
                                    :disabled="
                                        !selectedShow ||
                                        selectedSeasonNumber === null ||
                                        selectedEpisodeNumber === null ||
                                        isLookingUp ||
                                        identifyForm.processing
                                    "
                                    @click="previewSelectedIdentity(true)"
                                >
                                    <LoaderCircle
                                        v-if="
                                            previewLookup.processing ||
                                            identifyForm.processing
                                        "
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    {{
                                        previewLookup.processing ||
                                        identifyForm.processing
                                            ? 'Importing…'
                                            : 'Import'
                                    }}
                                </Button>
                            </DialogFooter>
                        </section>
                    </template>

                    <section v-else class="grid gap-5">
                        <DialogHeader>
                            <DialogTitle
                                >Confirm
                                {{ identityPreview.operation }}</DialogTitle
                            >
                            <DialogDescription>
                                Review the persisted identity and canonical
                                destination before changing files.
                            </DialogDescription>
                        </DialogHeader>
                        <dl
                            class="grid gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <dt
                                    class="text-xs text-muted-foreground uppercase"
                                >
                                    Identity
                                </dt>
                                <dd class="font-medium">
                                    <template v-if="'show' in identityPreview">
                                        {{ identityPreview.show.name }} · S{{
                                            String(
                                                identityPreview.show
                                                    .season_number,
                                            ).padStart(2, '0')
                                        }}E{{
                                            String(
                                                identityPreview.show
                                                    .episode_number,
                                            ).padStart(2, '0')
                                        }}
                                        ·
                                        {{ identityPreview.show.episode_name }}
                                    </template>
                                    <template v-else>
                                        {{ identityPreview.movie.title }}
                                        <span
                                            v-if="
                                                identityPreview.movie
                                                    .release_year
                                            "
                                            >({{
                                                identityPreview.movie
                                                    .release_year
                                            }})</span
                                        >
                                    </template>
                                </dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt
                                    class="text-xs text-muted-foreground uppercase"
                                >
                                    Destination
                                </dt>
                                <dd class="break-all">
                                    {{
                                        identityPreview.destination.disk_id
                                    }}:/{{
                                        identityPreview.destination
                                            .relative_path
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="identityPreview.blocker"
                            role="alert"
                            class="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            {{ identityPreview.blocker.message }}
                        </p>
                        <p
                            v-if="identifyForm.errors.tmdb_id"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ identifyForm.errors.tmdb_id }}
                        </p>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                @click="chooseAnotherIdentity"
                                >Choose another</Button
                            >
                            <Button
                                :disabled="
                                    Boolean(identityPreview.blocker) ||
                                    identifyForm.processing
                                "
                                @click="confirmIdentityAndImport"
                            >
                                <LoaderCircle
                                    v-if="identifyForm.processing"
                                    class="size-4 motion-safe:animate-spin"
                                />
                                {{
                                    identityPreview.operation === 'restore'
                                        ? 'Confirm restore'
                                        : 'Confirm import'
                                }}
                            </Button>
                        </DialogFooter>
                    </section>
                </template>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Delete discovered
                        {{
                            deleteTarget?.media_type === 'show'
                                ? 'Show episode'
                                : 'Movie'
                        }}
                        file?</DialogTitle
                    >
                    <DialogDescription>
                        This permanently deletes only the exact scanned file.
                        Sidecars are preserved unless guarded background cleanup
                        later confirms an entire source folder is residue.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="deleteTarget"
                    class="rounded-lg bg-muted p-3 text-sm break-all"
                >
                    {{ deleteTarget.disk_id }}:/{{ deleteTarget.relative_path }}
                </p>
                <p
                    v-if="deleteForm.errors.deletion_confirmed"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ deleteForm.errors.deletion_confirmed }}
                </p>
                <DialogFooter>
                    <Button variant="outline" @click="deleteOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="deleteForm.processing"
                        @click="confirmDelete"
                    >
                        Delete exact file
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="missingOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Confirm
                        {{
                            missingTarget?.media_type === 'show'
                                ? 'episode'
                                : 'Movie'
                        }}
                        file was removed?</DialogTitle
                    >
                    <DialogDescription>
                        The active path will be released while media provenance
                        and Show catalog metadata remain in history.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="missingTarget"
                    class="rounded-lg bg-muted p-3 text-sm break-all"
                >
                    {{ missingTarget.disk_id }}:/{{
                        missingTarget.relative_path
                    }}
                </p>
                <p
                    v-if="missingForm.errors.removal_confirmed"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ missingForm.errors.removal_confirmed }}
                </p>
                <DialogFooter>
                    <Button variant="outline" @click="missingOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="missingForm.processing"
                        @click="confirmMissing"
                    >
                        Confirm removed externally
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
