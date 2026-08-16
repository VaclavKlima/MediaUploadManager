<script setup lang="ts">
import {
    BadgeCheck,
    CircleAlert,
    CircleCheck,
    LoaderCircle,
    PencilLine,
    RefreshCw,
    Replace,
    Sparkles,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    EpisodeReviewGroup,
    EpisodeReviewRow,
    EpisodeReviewValidationStatus,
} from '@/composables/useSeriesUploadWizard';
import type { SequentialAssignmentPlan } from '@/lib/seriesEpisodeMatcher';
import type { ConfirmedSeries, ConfirmedSeriesEpisode } from '@/types/series';

const props = defineProps<{
    series: ConfirmedSeries;
    groups: EpisodeReviewGroup[];
    counts: { mapped: number; attention: number; replacements: number };
    ready: boolean;
    seasonHydrationStates: Partial<
        Record<number, 'idle' | 'loading' | 'error'>
    >;
    seasonHydrationErrors: Partial<Record<number, string>>;
    previewBulkAssignment: (
        sourceKeys: string[],
        seasonNumber: number,
        startingEpisodeNumber: number,
    ) => SequentialAssignmentPlan;
}>();

const emit = defineEmits<{
    setSeason: [sourceKey: string, seasonNumber: number | null];
    setEpisode: [sourceKey: string, episodeId: number | null];
    setReplacement: [sourceKey: string, confirmed: boolean];
    hydrateSeason: [seasonNumber: number, sourceKey: string | null];
    applyBulk: [
        sourceKeys: string[],
        seasonNumber: number,
        startingEpisodeNumber: number,
    ];
    continue: [];
}>();

type ReviewFilter = 'all' | 'ready' | 'attention';

type BulkDraft = {
    seasonNumber: number | null;
    startingEpisodeNumber: number | null;
};

const filter = ref<ReviewFilter>('all');
const bulkDrafts = reactive<Record<string, BulkDraft>>({});
const allRows = computed(() => props.groups.flatMap((group) => group.rows));
const rowIndex = computed(
    () =>
        new Map(allRows.value.map((row, index) => [row.sourceKey, index + 1])),
);

function isReady(row: EpisodeReviewRow): boolean {
    return row.validationStatus === 'auto' || row.validationStatus === 'edited';
}

function filteredRows(group: EpisodeReviewGroup): EpisodeReviewRow[] {
    if (filter.value === 'ready') {
        return group.rows.filter(isReady);
    }

    if (filter.value === 'attention') {
        return group.rows.filter((row) => !isReady(row));
    }

    return group.rows;
}

function visibleGroup(group: EpisodeReviewGroup): boolean {
    return filteredRows(group).length > 0;
}

function episodesFor(row: EpisodeReviewRow): ConfirmedSeriesEpisode[] {
    return (
        props.series.seasons.find(
            (season) => season.season_number === row.selectedSeasonNumber,
        )?.episodes ?? []
    );
}

function seasonState(
    seasonNumber: number | null,
): 'idle' | 'loading' | 'error' {
    return seasonNumber === null
        ? 'idle'
        : (props.seasonHydrationStates[seasonNumber] ?? 'idle');
}

function selectNumber(event: Event): number | null {
    const value = (event.target as HTMLSelectElement).value;

    return value === '' ? null : Number(value);
}

function statusLabel(status: EpisodeReviewValidationStatus): string {
    return {
        auto: 'Auto',
        edited: 'Edited',
        needs_assignment: 'Needs assignment',
        conflict: 'Conflict',
        replacement_required: 'Replacement required',
    }[status];
}

function statusVariant(
    status: EpisodeReviewValidationStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'auto') {
        return 'secondary';
    }

    if (status === 'edited') {
        return 'outline';
    }

    return 'destructive';
}

function statusIcon(status: EpisodeReviewValidationStatus) {
    if (status === 'auto') {
        return Sparkles;
    }

    if (status === 'edited') {
        return PencilLine;
    }

    if (status === 'replacement_required') {
        return Replace;
    }

    return CircleAlert;
}

function rowId(row: EpisodeReviewRow): string {
    return `episode-review-row-${rowIndex.value.get(row.sourceKey) ?? 0}`;
}

function scrollToFirstAttention(): void {
    const first = allRows.value.find((row) => !isReady(row));

    if (!first) {
        return;
    }

    const element = document.getElementById(rowId(first));
    element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    element?.focus({ preventScroll: true });
}

function bulkRows(group: EpisodeReviewGroup): EpisodeReviewRow[] {
    return group.rows.filter(
        (row) =>
            (row.validationStatus === 'needs_assignment' ||
                row.validationStatus === 'conflict') &&
            row.assignmentOrigin !== 'manual',
    );
}

function bulkDraft(group: EpisodeReviewGroup): BulkDraft {
    bulkDrafts[group.key] ??= {
        seasonNumber: null,
        startingEpisodeNumber: null,
    };

    return bulkDrafts[group.key];
}

function setBulkSeason(group: EpisodeReviewGroup, event: Event): void {
    const seasonNumber = selectNumber(event);
    const draft = bulkDraft(group);
    draft.seasonNumber = seasonNumber;
    draft.startingEpisodeNumber = null;

    if (seasonNumber !== null) {
        emit('hydrateSeason', seasonNumber, null);
    }
}

function bulkEpisodes(group: EpisodeReviewGroup): ConfirmedSeriesEpisode[] {
    const seasonNumber = bulkDraft(group).seasonNumber;

    return (
        props.series.seasons.find(
            (season) => season.season_number === seasonNumber,
        )?.episodes ?? []
    );
}

function bulkPlan(group: EpisodeReviewGroup): SequentialAssignmentPlan | null {
    const draft = bulkDraft(group);

    if (draft.seasonNumber === null || draft.startingEpisodeNumber === null) {
        return null;
    }

    return props.previewBulkAssignment(
        bulkRows(group).map((row) => row.sourceKey),
        draft.seasonNumber,
        draft.startingEpisodeNumber,
    );
}

function applyBulk(group: EpisodeReviewGroup): void {
    const draft = bulkDraft(group);
    const plan = bulkPlan(group);

    if (
        draft.seasonNumber === null ||
        draft.startingEpisodeNumber === null ||
        !plan ||
        plan.conflicts.length > 0
    ) {
        return;
    }

    emit(
        'applyBulk',
        bulkRows(group).map((row) => row.sourceKey),
        draft.seasonNumber,
        draft.startingEpisodeNumber,
    );
}

function planRange(plan: SequentialAssignmentPlan): string {
    const numbers = plan.assignments.map(
        (assignment) => assignment.episodeNumber,
    );

    if (numbers.length === 0) {
        return 'No episodes can be assigned.';
    }

    const first = Math.min(...numbers);
    const last = Math.max(...numbers);

    return first === last ? `Episode ${first}` : `Episodes ${first}–${last}`;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = units[0];

    for (let index = 1; index < units.length && value >= 1024; index += 1) {
        value /= 1024;
        unit = units[index];
    }

    return `${value.toFixed(value >= 10 ? 1 : 2)} ${unit}`;
}
</script>

<template>
    <section class="mx-auto flex w-full max-w-6xl flex-col gap-5">
        <div class="flex flex-col gap-2">
            <p class="text-xs font-medium text-primary">Step 3 of 6</p>
            <h2
                id="wizard-step-3"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Review episodes
            </h2>
            <p class="max-w-3xl text-sm leading-6 text-muted-foreground">
                Check every suggested match. Automatic assignments are only
                hints—season, episode, and permitted replacements remain
                editable until you continue.
            </p>
        </div>

        <section
            class="flex flex-col gap-4 rounded-2xl border bg-card p-4 sm:flex-row sm:items-center"
            aria-label="Confirmed show and review summary"
        >
            <div class="flex min-w-0 items-center gap-3">
                <div
                    class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-muted"
                >
                    <img
                        v-if="series.poster_url"
                        :src="series.poster_url"
                        :alt="`${series.name} poster`"
                        class="h-full w-full object-cover"
                    />
                    <BadgeCheck v-else class="size-6 text-muted-foreground" />
                </div>
                <div class="min-w-0">
                    <h3 class="truncate font-semibold">{{ series.name }}</h3>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ series.first_air_year ?? 'Year unknown' }} ·
                        {{ series.category === 'anime' ? 'Anime' : 'TV' }} ·
                        TMDB {{ series.tmdb_id }}
                    </p>
                </div>
            </div>
            <dl
                class="grid flex-1 grid-cols-3 overflow-hidden rounded-xl border sm:ml-auto sm:max-w-lg"
            >
                <div class="p-3 text-center">
                    <dt class="text-[11px] text-muted-foreground">Ready</dt>
                    <dd class="font-semibold">{{ counts.mapped }}</dd>
                </div>
                <div class="border-x p-3 text-center">
                    <dt class="text-[11px] text-muted-foreground">Attention</dt>
                    <dd class="font-semibold">{{ counts.attention }}</dd>
                </div>
                <div class="p-3 text-center">
                    <dt class="text-[11px] text-muted-foreground">
                        Replacements
                    </dt>
                    <dd class="font-semibold">{{ counts.replacements }}</dd>
                </div>
            </dl>
        </section>

        <div
            class="flex flex-col gap-3 rounded-xl border bg-muted/15 p-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div
                class="inline-flex w-fit rounded-lg border bg-background p-1"
                role="group"
                aria-label="Filter episode review"
            >
                <button
                    v-for="option in ['all', 'ready', 'attention'] as const"
                    :key="option"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-xs font-medium capitalize focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        filter === option
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="filter === option"
                    @click="filter = option"
                >
                    {{ option === 'attention' ? 'Needs attention' : option }}
                </button>
            </div>
            <Button
                v-if="counts.attention > 0"
                type="button"
                variant="ghost"
                size="sm"
                @click="scrollToFirstAttention"
            >
                <CircleAlert class="size-4" /> First unresolved file
            </Button>
            <span
                v-else
                class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400"
            >
                <CircleCheck class="size-4" /> Every file is ready
            </span>
        </div>

        <div class="flex flex-col gap-3">
            <details
                v-for="group in groups"
                v-show="visibleGroup(group)"
                :key="group.key"
                :open="group.attentionCount > 0"
                class="group overflow-hidden rounded-2xl border bg-card"
            >
                <summary
                    class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-5"
                >
                    <span class="min-w-0 flex-1 truncate font-medium">{{
                        group.label
                    }}</span>
                    <Badge variant="outline">
                        {{ filteredRows(group).length }}
                        {{
                            filteredRows(group).length === 1 ? 'file' : 'files'
                        }}
                    </Badge>
                    <Badge v-if="group.attentionCount" variant="destructive">
                        {{ group.attentionCount }} attention
                    </Badge>
                    <span
                        aria-hidden="true"
                        class="text-muted-foreground transition-transform group-open:rotate-180"
                        >⌄</span
                    >
                </summary>

                <div class="border-t">
                    <details
                        v-if="bulkRows(group).length > 0"
                        class="border-b bg-muted/20 px-4 py-3 sm:px-5"
                    >
                        <summary
                            class="cursor-pointer text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Assign in order · {{ bulkRows(group).length }}
                            unresolved
                        </summary>
                        <div
                            class="mt-4 grid gap-3 rounded-xl border bg-background p-4 lg:grid-cols-[1fr_1fr_auto] lg:items-end"
                        >
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium">Season</span>
                                <select
                                    class="h-9 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :value="bulkDraft(group).seasonNumber ?? ''"
                                    @change="setBulkSeason(group, $event)"
                                >
                                    <option value="">Choose season</option>
                                    <option
                                        v-for="season in series.available_seasons"
                                        :key="season.season_number"
                                        :value="season.season_number"
                                    >
                                        {{ season.name }} ·
                                        {{ season.episode_count }} episodes
                                    </option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium"
                                    >Starting episode</span
                                >
                                <select
                                    class="h-9 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                                    :disabled="
                                        bulkDraft(group).seasonNumber ===
                                            null ||
                                        seasonState(
                                            bulkDraft(group).seasonNumber,
                                        ) === 'loading' ||
                                        bulkEpisodes(group).length === 0
                                    "
                                    :value="
                                        bulkDraft(group)
                                            .startingEpisodeNumber ?? ''
                                    "
                                    @change="
                                        bulkDraft(group).startingEpisodeNumber =
                                            selectNumber($event)
                                    "
                                >
                                    <option value="">Choose episode</option>
                                    <option
                                        v-for="episode in bulkEpisodes(group)"
                                        :key="episode.id"
                                        :value="episode.episode_number"
                                    >
                                        {{ episode.identity }} ·
                                        {{ episode.name }}
                                    </option>
                                </select>
                            </label>
                            <Button
                                type="button"
                                :disabled="
                                    !bulkPlan(group) ||
                                    (bulkPlan(group)?.conflicts.length ?? 0) >
                                        0 ||
                                    (bulkPlan(group)?.assignments.length ??
                                        0) === 0
                                "
                                @click="applyBulk(group)"
                            >
                                Apply assignment
                            </Button>

                            <div
                                v-if="
                                    seasonState(
                                        bulkDraft(group).seasonNumber,
                                    ) === 'loading'
                                "
                                class="flex items-center gap-2 text-sm text-muted-foreground lg:col-span-3"
                                role="status"
                            >
                                <LoaderCircle class="size-4 animate-spin" />
                                Loading season episodes…
                            </div>
                            <div
                                v-else-if="
                                    bulkDraft(group).seasonNumber !== null &&
                                    seasonState(
                                        bulkDraft(group).seasonNumber,
                                    ) === 'error'
                                "
                                class="flex flex-wrap items-center gap-2 text-sm text-destructive lg:col-span-3"
                                role="alert"
                            >
                                {{
                                    seasonHydrationErrors[
                                        bulkDraft(group).seasonNumber as number
                                    ]
                                }}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        $emit(
                                            'hydrateSeason',
                                            bulkDraft(group)
                                                .seasonNumber as number,
                                            null,
                                        )
                                    "
                                >
                                    <RefreshCw class="size-3.5" /> Retry
                                </Button>
                            </div>
                            <div
                                v-else-if="bulkPlan(group)"
                                class="text-sm lg:col-span-3"
                                :class="
                                    bulkPlan(group)!.conflicts.length
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                                aria-live="polite"
                            >
                                Preview: {{ planRange(bulkPlan(group)!) }}.
                                <template
                                    v-if="bulkPlan(group)!.conflicts.length"
                                >
                                    {{ bulkPlan(group)!.conflicts.length }}
                                    {{
                                        bulkPlan(group)!.conflicts.length === 1
                                            ? 'conflict'
                                            : 'conflicts'
                                    }}
                                    must be resolved before applying.
                                </template>
                                <template v-else>
                                    No existing manual assignment will be
                                    overwritten.
                                </template>
                            </div>
                        </div>
                    </details>

                    <div
                        v-for="row in filteredRows(group)"
                        :id="rowId(row)"
                        :key="row.sourceKey"
                        tabindex="-1"
                        class="grid gap-4 border-b p-4 outline-none last:border-b-0 focus:bg-muted/20 sm:p-5 xl:grid-cols-[minmax(12rem,1fr)_12rem_minmax(16rem,1.3fr)] xl:items-start"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-medium">
                                    {{ row.source.filename }}
                                </p>
                                <Badge
                                    :variant="
                                        statusVariant(row.validationStatus)
                                    "
                                    class="gap-1"
                                >
                                    <component
                                        :is="statusIcon(row.validationStatus)"
                                        class="size-3"
                                    />
                                    {{ statusLabel(row.validationStatus) }}
                                </Badge>
                            </div>
                            <p
                                v-if="
                                    row.source.relativePath !==
                                    row.source.filename
                                "
                                class="mt-1 truncate text-xs text-muted-foreground"
                                :title="row.source.relativePath"
                            >
                                {{ row.source.relativePath }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ formatBytes(row.source.size) }}
                                <template v-if="row.hint">
                                    · Hint {{ row.hint.identity }}
                                </template>
                                <template v-else>
                                    · No safe automatic hint
                                </template>
                            </p>
                        </div>

                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium">Season</span>
                            <select
                                class="h-9 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="`Season for ${row.source.filename}`"
                                :value="row.selectedSeasonNumber ?? ''"
                                @change="
                                    $emit(
                                        'setSeason',
                                        row.sourceKey,
                                        selectNumber($event),
                                    )
                                "
                            >
                                <option value="">Choose season</option>
                                <option
                                    v-for="season in series.available_seasons"
                                    :key="season.season_number"
                                    :value="season.season_number"
                                >
                                    {{ season.name }}
                                </option>
                            </select>
                        </label>

                        <div class="flex min-w-0 flex-col gap-2">
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium">Episode</span>
                                <select
                                    class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                                    :aria-label="`Episode for ${row.source.filename}`"
                                    :disabled="
                                        row.selectedSeasonNumber === null ||
                                        seasonState(
                                            row.selectedSeasonNumber,
                                        ) === 'loading' ||
                                        episodesFor(row).length === 0
                                    "
                                    :value="row.seriesEpisodeId ?? ''"
                                    @change="
                                        $emit(
                                            'setEpisode',
                                            row.sourceKey,
                                            selectNumber($event),
                                        )
                                    "
                                >
                                    <option value="">Choose episode</option>
                                    <option
                                        v-for="episode in episodesFor(row)"
                                        :key="episode.id"
                                        :value="episode.id"
                                    >
                                        {{ episode.identity }} ·
                                        {{ episode.name }}
                                    </option>
                                </select>
                            </label>

                            <div
                                v-if="
                                    seasonState(row.selectedSeasonNumber) ===
                                    'loading'
                                "
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                                role="status"
                            >
                                <LoaderCircle class="size-3.5 animate-spin" />
                                Loading episode choices…
                            </div>
                            <div
                                v-else-if="
                                    row.selectedSeasonNumber !== null &&
                                    seasonState(row.selectedSeasonNumber) ===
                                        'error'
                                "
                                class="flex flex-wrap items-center gap-2 text-xs text-destructive"
                                role="alert"
                            >
                                {{
                                    seasonHydrationErrors[
                                        row.selectedSeasonNumber
                                    ]
                                }}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        $emit(
                                            'hydrateSeason',
                                            row.selectedSeasonNumber as number,
                                            row.sourceKey,
                                        )
                                    "
                                >
                                    <RefreshCw class="size-3.5" /> Retry
                                </Button>
                            </div>

                            <div
                                v-if="row.selectedEpisode?.has_current_primary"
                                class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-xs"
                            >
                                <p class="font-medium">
                                    This episode already has a primary video
                                </p>
                                <p class="mt-1 break-all text-muted-foreground">
                                    {{
                                        row.selectedEpisode.current_primary
                                            ?.relative_path
                                    }}
                                </p>
                                <label
                                    class="mt-2 flex items-start gap-2"
                                    :class="
                                        row.selectedEpisode
                                            .can_replace_current_primary
                                            ? 'cursor-pointer'
                                            : 'cursor-not-allowed opacity-60'
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 size-4 rounded border-input accent-primary"
                                        :checked="row.replacementConfirmed"
                                        :disabled="
                                            !row.selectedEpisode
                                                .can_replace_current_primary
                                        "
                                        :aria-label="`Replace current primary for ${row.selectedEpisode.identity}`"
                                        @change="
                                            $emit(
                                                'setReplacement',
                                                row.sourceKey,
                                                (
                                                    $event.target as HTMLInputElement
                                                ).checked,
                                            )
                                        "
                                    />
                                    <span>
                                        <template
                                            v-if="
                                                row.selectedEpisode
                                                    .can_replace_current_primary
                                            "
                                        >
                                            Replace this exact current primary
                                            video
                                        </template>
                                        <template v-else>
                                            Only its owner or an administrator
                                            can replace this video.
                                        </template>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </details>
        </div>

        <div
            class="sticky bottom-0 z-10 flex flex-col gap-3 rounded-2xl border bg-card/95 p-4 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-muted-foreground" aria-live="polite">
                <template v-if="ready">
                    All {{ counts.mapped }} files have unique episode targets.
                </template>
                <template v-else>
                    Resolve {{ counts.attention }}
                    {{ counts.attention === 1 ? 'file' : 'files' }} before
                    continuing.
                </template>
            </p>
            <Button type="button" :disabled="!ready" @click="$emit('continue')">
                <CircleCheck class="size-4" /> Confirm episode review
            </Button>
        </div>
    </section>
</template>
