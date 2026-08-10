<script setup lang="ts">
import { Head, router, useForm, useHttp, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    CircleDot,
    FolderSearch,
    HardDrive,
    LoaderCircle,
    RefreshCw,
    Search,
    Trash2,
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
import { index as scanIndex, store as startScan } from '@/routes/library_scans';
import { index as moviesIndex } from '@/routes/movies';
import type {
    IdentityPreview,
    IdentityPreviewResponse,
    LibraryHistoryItem,
    LibraryScanProgress,
    LibraryScanSummary,
    LibraryTask,
    MaintenanceWarning,
    UnavailableDisk,
} from '@/types/library-scan';
import type {
    DetailsResponse,
    MovieSummary,
    SearchResponse,
} from '@/types/movie-upload';

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
    layout: {
        breadcrumbs: [
            { title: 'Movies', href: moviesIndex() },
            { title: 'Library scan', href: scanIndex() },
        ],
    },
});

const scanForm = useForm({});
const actionForm = useForm({ finding: '' });
const hiddenTaskIds = ref<Set<number>>(new Set());
const activeTaskId = ref<number | null>(null);
const lastQueuePosition = ref(0);
const taskCard = ref<HTMLElement | null>(null);
const identifyTarget = ref<LibraryTask | null>(null);
const identifyOpen = ref(false);
const searchInput = ref('');
const searchResults = ref<MovieSummary[]>([]);
const selectedMovie = ref<MovieSummary | null>(null);
const identityPreview = ref<IdentityPreview | null>(null);
const lookupCompleted = ref(false);
const lookupError = ref('');
const textLookup = useHttp<{ query: string }, SearchResponse>({ query: '' });
const detailsLookup = useHttp<Record<string, never>, DetailsResponse>({});
const previewLookup = useHttp<{ tmdb_id: number }, IdentityPreviewResponse>({
    tmdb_id: 0,
});
const identifyForm = useForm({
    tmdb_id: 0,
    destination_relative_path: '',
});
const deleteTarget = ref<LibraryTask | null>(null);
const deleteOpen = ref(false);
const deleteForm = useForm({ deletion_confirmed: true });
const missingTarget = ref<LibraryTask | null>(null);
const missingOpen = ref(false);
const missingForm = useForm({ removal_confirmed: true });
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

const visibleTasks = computed(() =>
    props.tasks.filter((task) => !hiddenTaskIds.value.has(task.id)),
);
const activeTask = computed(() => {
    const selectedTask = visibleTasks.value.find(
        (task) => task.id === activeTaskId.value,
    );

    return (
        selectedTask ??
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

function reconcileQueue(): void {
    const tasksById = new Map(props.tasks.map((task) => [task.id, task]));
    hiddenTaskIds.value = new Set(
        [...hiddenTaskIds.value].filter((id) => {
            const task = tasksById.get(id);

            return task !== undefined && task.status !== 'failed';
        }),
    );

    const nextTask = activeTask.value;
    activeTaskId.value = nextTask?.id ?? null;
}

const { start: startQueuePolling, stop: stopQueuePolling } = usePoll(
    2000,
    {
        only: queuePropNames,
        onSuccess: reconcileQueue,
    },
    {
        mode: 'rest',
    },
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

    return new Intl.NumberFormat(undefined, {
        style: 'unit',
        unit: bytes >= 1_073_741_824 ? 'gigabyte' : 'megabyte',
        unitDisplay: 'short',
        maximumFractionDigits: 2,
    }).format(bytes / (bytes >= 1_073_741_824 ? 1_073_741_824 : 1_048_576));
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
    return {
        identify: 'Identify movie',
        import: 'Ready to import',
        restore: 'Verified moved file',
        retry_import: 'Import failed',
        retry_restore: 'Restore failed',
        retry_delete: 'Deletion failed',
        missing: 'Tracked file missing',
    }[task.task_type];
}

function outcomeLabel(outcome: string | null): string {
    return (outcome ?? 'completed').replaceAll('_', ' ');
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

function openIdentify(task: LibraryTask): void {
    identifyTarget.value = task;
    searchInput.value = task.title ?? task.source_filename;
    searchResults.value = [];
    selectedMovie.value = null;
    identityPreview.value = null;
    lookupCompleted.value = false;
    lookupError.value = '';
    identifyForm.reset();
    identifyForm.clearErrors();
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
            payload.errors?.finding?.[0] ??
            payload.message ??
            fallback
        );
    } catch {
        return fallback;
    }
}

async function searchMovies(): Promise<void> {
    const query = searchInput.value.normalize('NFC').trim();

    if (!query) {
        return;
    }

    lookupError.value = '';
    lookupCompleted.value = false;
    searchResults.value = [];
    selectedMovie.value = null;
    identityPreview.value = null;

    try {
        if (/^tt\d{7,12}$/i.test(query)) {
            const response = await detailsLookup.get(
                MovieController.showImdb.url(query.toLowerCase()),
            );
            searchResults.value = [response.data];
        } else if (/^\d+$/.test(query)) {
            const response = await detailsLookup.get(
                MovieController.showTmdb.url(Number(query)),
            );
            searchResults.value = [response.data];
        } else {
            textLookup.query = query;
            const response = await textLookup.get(
                MovieController.search.url(),
                {
                    onHttpException: (exception) => {
                        lookupError.value = readHttpError(
                            exception.data,
                            'Movie lookup failed.',
                        );
                    },
                },
            );
            searchResults.value = response.data;
        }

        lookupCompleted.value = true;
    } catch {
        lookupCompleted.value = true;
        lookupError.value ||= 'Movie lookup failed. Please try again.';
    }
}

async function previewSelectedIdentity(): Promise<void> {
    if (!identifyTarget.value || !selectedMovie.value) {
        return;
    }

    lookupError.value = '';
    previewLookup.tmdb_id = selectedMovie.value.tmdb_id;

    try {
        const response = await previewLookup.get(
            previewIdentity.url(identifyTarget.value.id),
            {
                onHttpException: (exception) => {
                    lookupError.value = readHttpError(
                        exception.data,
                        'Identity preview failed.',
                    );
                },
            },
        );
        identityPreview.value = response.data;
    } catch {
        lookupError.value ||= 'Identity preview failed. Please try again.';
    }
}

function confirmIdentityAndImport(): void {
    if (
        !identifyTarget.value ||
        !identityPreview.value ||
        identityPreview.value.blocker
    ) {
        return;
    }

    identifyForm.tmdb_id = identityPreview.value.movie.tmdb_id;
    identifyForm.destination_relative_path =
        identityPreview.value.destination.relative_path;
    identifyForm.submit(identifyAndImport(identifyTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            const taskId = identifyTarget.value?.id;
            identifyOpen.value = false;

            if (taskId !== undefined) {
                hideTaskAndAdvance(taskId);
            }
        },
    });
}

function chooseAnotherIdentity(): void {
    identityPreview.value = null;
    selectedMovie.value = null;
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
                        Work through the highest-priority movie first.
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
                    >{{ unavailable.length }} unavailable disk<span
                        v-if="unavailable.length !== 1"
                        >s</span
                    >.</strong
                >
                Missing-file checks were skipped for
                {{ unavailable.map((disk) => disk.label).join(', ') }}.
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
            <div class="flex items-center justify-between gap-3">
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
                        <div class="mb-2 flex items-center gap-2">
                            <CircleDot class="size-4 text-primary" />
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
                    <div class="grid gap-1">
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
                    <div v-if="activeTask.title" class="grid gap-1">
                        <dt
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Movie
                        </dt>
                        <dd class="font-medium">
                            {{ activeTask.title }}
                            <span v-if="activeTask.release_year">
                                ({{ activeTask.release_year }})
                            </span>
                            <span
                                v-if="activeTask.tmdb_id"
                                class="block text-xs font-normal text-muted-foreground"
                            >
                                TMDB {{ activeTask.tmdb_id }}
                            </span>
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
                        v-else-if="activeTask.task_type === 'restore'"
                        :disabled="actionForm.processing"
                        @click="queueCurrentRestore(activeTask)"
                    >
                        <LoaderCircle
                            v-if="actionForm.processing"
                            class="size-4 motion-safe:animate-spin"
                        />
                        Restore moved file
                    </Button>
                    <Button
                        v-else-if="activeTask.task_type === 'retry_restore'"
                        :disabled="actionForm.processing"
                        @click="queueCurrentRestore(activeTask)"
                    >
                        <RefreshCw class="size-4" /> Retry restore
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
                There are no unresolved library tasks from the latest scan.
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
                    class="grid gap-1 px-4 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-center"
                >
                    <span class="truncate font-medium">{{ item.name }}</span>
                    <span class="text-xs text-muted-foreground sm:text-right">
                        <span class="capitalize">{{
                            outcomeLabel(item.outcome)
                        }}</span>
                        · {{ formatTime(item.completed_at) }}
                    </span>
                </li>
            </ul>
        </details>

        <Dialog v-model:open="identifyOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
                <DialogTitle class="sr-only">Identify movie file</DialogTitle>
                <IdentifyMovieStep
                    v-if="!identityPreview"
                    v-model:search-input="searchInput"
                    :source-filename="identifyTarget?.source_filename ?? ''"
                    :results="searchResults"
                    :selected-movie="selectedMovie"
                    :parsed-filename="null"
                    :is-looking-up="
                        textLookup.processing ||
                        detailsLookup.processing ||
                        previewLookup.processing
                    "
                    :is-confirming="previewLookup.processing"
                    :lookup-completed="lookupCompleted"
                    :error-message="lookupError"
                    step-label="Library task"
                    heading="Choose movie"
                    @search="searchMovies"
                    @select="selectedMovie = $event"
                    @confirm="previewSelectedIdentity"
                />

                <section v-else class="grid gap-5">
                    <div>
                        <p class="text-xs font-medium text-primary">
                            Identity preview
                        </p>
                        <h2 class="mt-1 text-2xl font-semibold tracking-tight">
                            {{ identityPreview.movie.title }}
                            <span v-if="identityPreview.movie.release_year">
                                ({{ identityPreview.movie.release_year }})
                            </span>
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{
                                identityPreview.operation === 'restore'
                                    ? 'The found bytes match the missing tracked primary.'
                                    : 'Confirm the exact same-disk move before queuing it.'
                            }}
                        </p>
                    </div>

                    <dl
                        class="grid gap-4 rounded-xl border bg-muted/20 p-4 text-sm"
                    >
                        <div class="grid gap-1">
                            <dt
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Source
                            </dt>
                            <dd class="break-all">
                                {{ identityPreview.source.disk_id }}:/{{
                                    identityPreview.source.relative_path
                                }}
                            </dd>
                            <dd class="text-xs text-muted-foreground">
                                {{
                                    formatBytes(
                                        identityPreview.source.size_bytes,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="grid gap-1 border-t pt-4">
                            <dt
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Canonical destination
                            </dt>
                            <dd class="font-medium break-all">
                                {{ identityPreview.destination.disk_id }}:/{{
                                    identityPreview.destination.relative_path
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="identityPreview.relocation"
                            class="grid gap-1 border-t pt-4"
                        >
                            <dt
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Missing tracked path
                            </dt>
                            <dd class="font-medium break-all">
                                {{ identityPreview.relocation.disk_id }}:/{{
                                    identityPreview.relocation.relative_path
                                }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="identityPreview.blocker"
                        role="alert"
                        class="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    >
                        {{ identityPreview.blocker.message }} Choose another
                        identity or close this dialog to delete the file.
                    </p>
                    <p
                        v-if="identifyForm.errors.tmdb_id"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ identifyForm.errors.tmdb_id }}
                    </p>

                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            variant="outline"
                            @click="chooseAnotherIdentity"
                        >
                            Choose another
                        </Button>
                        <Button
                            :disabled="
                                identifyForm.processing ||
                                identityPreview.blocker !== null
                            "
                            @click="confirmIdentityAndImport"
                        >
                            <LoaderCircle
                                v-if="identifyForm.processing"
                                class="size-4 motion-safe:animate-spin"
                            />
                            {{
                                identityPreview.operation === 'restore'
                                    ? 'Identify & restore moved file'
                                    : 'Identify & import'
                            }}
                        </Button>
                    </div>
                </section>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            deleteTarget?.task_type === 'retry_delete'
                                ? 'Retry file deletion?'
                                : 'Delete discovered file?'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        This permanently deletes only the exact verified file.
                        Background maintenance may remove its empty old folder.
                    </DialogDescription>
                </DialogHeader>
                <dl
                    v-if="deleteTarget"
                    class="grid gap-2 rounded-lg bg-muted p-3 text-sm"
                >
                    <div>
                        <dt class="inline font-medium">Disk:</dt>
                        <dd class="inline">{{ deleteTarget.disk_id }}</dd>
                    </div>
                    <div class="break-all">
                        <dt class="inline font-medium">Path:</dt>
                        <dd class="inline">{{ deleteTarget.relative_path }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium">Size:</dt>
                        <dd class="inline">
                            {{ formatBytes(deleteTarget.size_bytes) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="deleteForm.errors.deletion_confirmed"
                    class="text-sm text-destructive"
                >
                    {{ deleteForm.errors.deletion_confirmed }}
                </p>
                <DialogFooter>
                    <Button
                        variant="destructive"
                        :disabled="deleteForm.processing"
                        @click="confirmDelete"
                    >
                        {{
                            deleteTarget?.task_type === 'retry_delete'
                                ? 'Retry deletion'
                                : 'Delete file'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="missingOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Confirm removed externally?</DialogTitle>
                    <DialogDescription>
                        The movie identity and historical technical metadata
                        will be retained for a future upload.
                    </DialogDescription>
                </DialogHeader>
                <dl
                    v-if="missingTarget"
                    class="grid gap-2 rounded-lg bg-muted p-3 text-sm"
                >
                    <div>
                        <dt class="inline font-medium">Disk:</dt>
                        <dd class="inline">{{ missingTarget.disk_id }}</dd>
                    </div>
                    <div class="break-all">
                        <dt class="inline font-medium">Path:</dt>
                        <dd class="inline">
                            {{ missingTarget.relative_path }}
                        </dd>
                    </div>
                    <div>
                        <dt class="inline font-medium">Size:</dt>
                        <dd class="inline">
                            {{ formatBytes(missingTarget.size_bytes) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="missingForm.errors.removal_confirmed"
                    class="text-sm text-destructive"
                >
                    {{ missingForm.errors.removal_confirmed }}
                </p>
                <DialogFooter>
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
