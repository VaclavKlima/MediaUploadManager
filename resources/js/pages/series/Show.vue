<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarDays,
    HardDrive,
    Library,
    LoaderCircle,
    MoreVertical,
    Pencil,
    Trash2,
    Upload,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import {
    preview as previewEpisodeRename,
    update as updateEpisode,
} from '@/actions/App/Http/Controllers/Series/EpisodeRenameController';
import { hydrateSeason } from '@/actions/App/Http/Controllers/Series/SeriesLookupController';
import {
    episode as deleteEpisode,
    season as deleteSeason,
    series as deleteShow,
} from '@/actions/App/Http/Controllers/Series/SeriesMediaDeletionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';
import {
    index as seriesIndex,
    show as seriesShow,
    upload as seriesUpload,
} from '@/routes/series';
import type {
    EpisodeRenamePreview,
    SeriesEpisodeDetails,
    SeriesShowDetails,
} from '@/types/series';

const props = defineProps<{ show: SeriesShowDetails }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Shows', href: seriesIndex() },
        ],
    },
});

const hydration = useHttp<Record<string, never>, { data: unknown }>({});
const hydrationError = ref('');
let hydrationRevision = 0;

const renameOpen = ref(false);
const selectedEpisode = ref<SeriesEpisodeDetails | null>(null);
const renamePreview = ref<EpisodeRenamePreview | null>(null);
const previewRequest = useHttp<
    { custom_name: string | null },
    { data: EpisodeRenamePreview }
>({ custom_name: null });
let previewRevision = 0;
const renameForm = useForm({
    custom_name: null as string | null,
    rename_confirmed: false,
});
const renameError = computed(
    () => (renameForm.errors as Record<string, string | undefined>).rename,
);

type DeleteScope = 'episode' | 'season' | 'series';
const deleteOpen = ref(false);
const deleteScope = ref<DeleteScope>('episode');
const deleteEpisodeTarget = ref<SeriesEpisodeDetails | null>(null);
const deleteForm = useForm({
    deletion_confirmed: false,
    confirmation_name: '',
});
const deletionError = computed(
    () => (deleteForm.errors as Record<string, string | undefined>).deletion,
);

const selectedSeason = computed(() => props.show.selected_season);
const deleteTitle = computed(() => {
    if (deleteScope.value === 'series') {
        return 'Permanently delete Show';
    }

    if (deleteScope.value === 'season') {
        return 'Delete season media';
    }

    return 'Delete episode media';
});

watch(
    () =>
        [
            props.show.id,
            props.show.selected_season_number,
            props.show.selected_season_hydrated,
        ] as const,
    ([showId, seasonNumber, hydrated]) => {
        if (hydrated) {
            return;
        }

        void hydrateSelectedSeason(showId, seasonNumber);
    },
    { immediate: true },
);

async function hydrateSelectedSeason(
    showId: number,
    seasonNumber: number,
): Promise<void> {
    const revision = ++hydrationRevision;
    hydration.cancel();
    hydrationError.value = '';

    try {
        await hydration.post(
            hydrateSeason.url({ series: showId, seasonNumber }),
        );

        if (
            revision !== hydrationRevision ||
            props.show.id !== showId ||
            props.show.selected_season_number !== seasonNumber
        ) {
            return;
        }

        router.reload({
            only: ['show'],
        });
    } catch {
        if (revision === hydrationRevision) {
            hydrationError.value =
                'This season could not be loaded. Try again.';
        }
    }
}

function selectSeason(value: unknown): void {
    const season = Number(value);

    if (!Number.isSafeInteger(season) || season < 0) {
        return;
    }

    hydrationRevision += 1;
    hydration.cancel();
    router.get(
        seriesShow.url(props.show.id),
        { season },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['show'],
        },
    );
}

function openRename(episode: SeriesEpisodeDetails): void {
    selectedEpisode.value = episode;
    renameForm.custom_name = episode.custom_name;
    renameForm.rename_confirmed = false;
    renameForm.clearErrors();
    renamePreview.value = null;
    renameOpen.value = true;
}

async function requestRenamePreview(): Promise<void> {
    const episode = selectedEpisode.value;
    const season = selectedSeason.value;

    if (!episode || !season || !renameOpen.value) {
        return;
    }

    const revision = ++previewRevision;
    previewRequest.cancel();
    previewRequest.custom_name = renameForm.custom_name?.trim() || null;

    try {
        const response = await previewRequest.post(
            previewEpisodeRename.url({
                series: props.show.id,
                season: season.id,
                episode: episode.id,
            }),
        );

        if (
            revision === previewRevision &&
            selectedEpisode.value?.id === episode.id
        ) {
            renamePreview.value = response.data;
        }
    } catch {
        if (revision === previewRevision) {
            renamePreview.value = null;
        }
    }
}

const debouncedRenamePreview = useDebounceFn(requestRenamePreview, 300);
watch(
    () => renameForm.custom_name,
    () => {
        renameForm.rename_confirmed = false;
        renamePreview.value = null;
        void debouncedRenamePreview();
    },
);
watch(renameOpen, (open) => {
    if (open) {
        void requestRenamePreview();
    } else {
        previewRequest.cancel();
    }
});

function submitRename(): void {
    const episode = selectedEpisode.value;
    const season = selectedSeason.value;

    if (
        !episode ||
        !season ||
        !renamePreview.value?.can_rename ||
        !renameForm.rename_confirmed
    ) {
        return;
    }

    renameForm.patch(
        updateEpisode.url({
            series: props.show.id,
            season: season.id,
            episode: episode.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => (renameOpen.value = false),
        },
    );
}

function openDelete(
    scope: DeleteScope,
    episode: SeriesEpisodeDetails | null = null,
): void {
    deleteScope.value = scope;
    deleteEpisodeTarget.value = episode;
    deleteForm.reset();
    deleteForm.clearErrors();
    deleteOpen.value = true;
}

function submitDelete(): void {
    const season = selectedSeason.value;

    if (!deleteForm.deletion_confirmed || deleteForm.processing) {
        return;
    }

    let url: string;

    if (deleteScope.value === 'series') {
        url = deleteShow.url(props.show.id);
    } else if (deleteScope.value === 'season' && season) {
        url = deleteSeason.url({ series: props.show.id, season: season.id });
    } else if (season && deleteEpisodeTarget.value) {
        url = deleteEpisode.url({
            series: props.show.id,
            season: season.id,
            episode: deleteEpisodeTarget.value.id,
        });
    } else {
        return;
    }

    deleteForm.delete(url, {
        preserveScroll: true,
        onSuccess: () => (deleteOpen.value = false),
    });
}

function formatBytes(bytes: number): string {
    return new Intl.NumberFormat(undefined, {
        style: 'unit',
        unit: bytes >= 1_073_741_824 ? 'gigabyte' : 'megabyte',
        unitDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(bytes / (bytes >= 1_073_741_824 ? 1_073_741_824 : 1_048_576));
}

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              new Date(`${value}T00:00:00`),
          )
        : 'No air date';
}

const stateLabels = {
    available: 'Available',
    missing: 'Missing',
    upcoming: 'Upcoming',
    unscheduled: 'Unscheduled',
} as const;

const stateClasses = {
    available:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    missing: 'border-destructive/30 bg-destructive/10 text-destructive',
    upcoming:
        'border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-300',
    unscheduled: 'border-muted-foreground/30 bg-muted text-muted-foreground',
} as const;
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <Head :title="show.name" />

        <section
            class="grid gap-5 rounded-2xl border bg-card p-5 shadow-sm sm:grid-cols-[8rem_1fr]"
        >
            <div class="aspect-[2/3] overflow-hidden rounded-xl bg-muted">
                <img
                    v-if="show.poster_url"
                    :src="show.poster_url"
                    :alt="`${show.name} poster`"
                    class="size-full object-cover"
                />
                <span
                    v-else
                    class="flex size-full items-center justify-center text-muted-foreground"
                    ><Library class="size-9"
                /></span>
            </div>
            <div class="flex min-w-0 flex-col gap-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge>{{
                                show.category === 'anime' ? 'Anime' : 'TV'
                            }}</Badge>
                            <Badge variant="outline">{{
                                show.year ?? 'Year unknown'
                            }}</Badge>
                            <Badge variant="outline"
                                >TMDB {{ show.tmdb_id }}</Badge
                            >
                        </div>
                        <h1
                            class="mt-2 text-2xl font-semibold tracking-tight md:text-3xl"
                        >
                            {{ show.name }}
                        </h1>
                        <p
                            v-if="
                                show.original_name &&
                                show.original_name !== show.name
                            "
                            class="text-sm text-muted-foreground"
                        >
                            {{ show.original_name }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child
                            ><Link
                                :href="
                                    seriesUpload({ query: { series: show.id } })
                                "
                                ><Upload class="size-4" />Upload episodes</Link
                            ></Button
                        >
                        <Button
                            variant="destructive"
                            :disabled="!show.actions.can_delete_show"
                            :title="
                                show.actions.delete_show_blocker ?? undefined
                            "
                            @click="openDelete('series')"
                            ><Trash2 class="size-4" />Delete Show</Button
                        >
                    </div>
                </div>
                <p class="max-w-4xl text-sm leading-6 text-muted-foreground">
                    {{ show.overview || 'No overview is available.' }}
                </p>
                <dl class="flex flex-wrap gap-2 text-xs">
                    <div class="rounded-lg border bg-muted/30 px-3 py-2">
                        <dt class="text-muted-foreground">Seasons</dt>
                        <dd class="font-semibold">
                            {{ show.coverage.seasons.available }}/{{
                                show.coverage.seasons.total
                            }}
                        </dd>
                    </div>
                    <div class="rounded-lg border bg-muted/30 px-3 py-2">
                        <dt class="text-muted-foreground">Episodes</dt>
                        <dd class="font-semibold">
                            {{ show.coverage.episodes.available }}/{{
                                show.coverage.episodes.total
                            }}
                        </dd>
                    </div>
                    <div class="rounded-lg border bg-muted/30 px-3 py-2">
                        <dt
                            class="flex items-center gap-1 text-muted-foreground"
                        >
                            <HardDrive class="size-3" />Storage
                        </dt>
                        <dd class="font-semibold">
                            {{
                                show.storage.disk_label ??
                                show.storage.disk_id ??
                                'Not assigned'
                            }}
                            · {{ formatBytes(show.storage.size_bytes) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <div class="lg:hidden">
            <Select
                :model-value="String(show.selected_season_number)"
                @update:model-value="selectSeason"
            >
                <SelectTrigger aria-label="Choose season"
                    ><SelectValue
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="season in show.seasons"
                        :key="season.season_number"
                        :value="String(season.season_number)"
                    >
                        {{ season.name }} · {{ season.episode_count }} episodes
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <section class="grid min-h-0 gap-5 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <nav
                class="hidden self-start rounded-xl border bg-card p-2 lg:grid"
                aria-label="Show seasons"
            >
                <Link
                    v-for="season in show.seasons"
                    :key="season.season_number"
                    :href="
                        seriesShow(show.id, {
                            query: { season: season.season_number },
                        })
                    "
                    preserve-state
                    preserve-scroll
                    :class="[
                        'flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        season.season_number === show.selected_season_number
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                    ]"
                >
                    <span class="truncate">{{ season.name }}</span>
                    <span class="text-xs opacity-70">{{
                        season.episode_count
                    }}</span>
                </Link>
            </nav>

            <div class="min-w-0 rounded-xl border bg-card shadow-sm">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-4"
                >
                    <div>
                        <h2 class="font-semibold">
                            {{
                                selectedSeason?.name ??
                                show.seasons.find(
                                    (season) =>
                                        season.season_number ===
                                        show.selected_season_number,
                                )?.name
                            }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Specials stay visible here and do not affect Show
                            coverage.
                        </p>
                    </div>
                    <Button
                        v-if="selectedSeason"
                        variant="destructive"
                        size="sm"
                        :disabled="!selectedSeason.actions.can_delete_media"
                        :title="
                            selectedSeason.actions.delete_media_blocker ??
                            undefined
                        "
                        @click="openDelete('season')"
                        ><Trash2 class="size-4" />Delete season media</Button
                    >
                </div>

                <div
                    v-if="!show.selected_season_hydrated"
                    class="grid gap-3 p-4"
                    aria-live="polite"
                >
                    <template v-if="!hydrationError">
                        <div
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <LoaderCircle class="size-4 animate-spin" />Loading
                            season episodes…
                        </div>
                        <Skeleton
                            v-for="index in 5"
                            :key="index"
                            class="h-20 w-full"
                        />
                    </template>
                    <div
                        v-else
                        class="flex flex-col items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4"
                    >
                        <p class="text-sm text-destructive">
                            {{ hydrationError }}
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="
                                hydrateSelectedSeason(
                                    show.id,
                                    show.selected_season_number,
                                )
                            "
                            >Retry</Button
                        >
                    </div>
                </div>

                <div
                    v-else-if="selectedSeason?.episodes.length"
                    class="divide-y"
                >
                    <article
                        v-for="episode in selectedSeason.episodes"
                        :key="episode.id"
                        class="grid gap-3 p-4 md:grid-cols-[4.5rem_minmax(0,1fr)_auto] md:items-start"
                    >
                        <div class="flex items-center justify-between md:block">
                            <span class="font-mono text-sm font-semibold"
                                >E{{
                                    String(episode.episode_number).padStart(
                                        2,
                                        '0',
                                    )
                                }}</span
                            >
                            <Badge
                                variant="outline"
                                :class="[
                                    'md:mt-2',
                                    stateClasses[episode.state],
                                ]"
                                >{{ stateLabels[episode.state] }}</Badge
                            >
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-medium">{{ episode.name }}</h3>
                                <Badge
                                    v-if="episode.custom_name"
                                    variant="secondary"
                                    class="text-[10px]"
                                    >Custom title</Badge
                                >
                            </div>
                            <p
                                class="mt-1 line-clamp-2 text-xs leading-5 text-muted-foreground"
                            >
                                {{ episode.overview || 'No episode overview.' }}
                            </p>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <span class="flex items-center gap-1"
                                    ><CalendarDays class="size-3" />{{
                                        formatDate(episode.air_date)
                                    }}</span
                                >
                                <template v-if="episode.current_file">
                                    <Badge
                                        v-for="tag in episode.current_file
                                            .technical_tags"
                                        :key="`${tag.kind}:${tag.label}`"
                                        variant="secondary"
                                        class="h-5 px-1.5 py-0 text-[10px]"
                                        >{{ tag.label }}</Badge
                                    >
                                    <span>{{
                                        formatBytes(
                                            episode.current_file.size_bytes,
                                        )
                                    }}</span>
                                </template>
                            </div>
                            <p
                                v-if="episode.current_file"
                                class="mt-1 truncate font-mono text-[10px] text-muted-foreground"
                                :title="episode.current_file.relative_path"
                            >
                                {{ episode.current_file.relative_path }}
                            </p>
                        </div>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child
                                ><Button
                                    size="icon-sm"
                                    variant="ghost"
                                    :aria-label="`Actions for ${episode.identity}`"
                                    ><MoreVertical class="size-4" /></Button
                            ></DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-64">
                                <DropdownMenuItem
                                    :disabled="!episode.actions.can_rename"
                                    :title="
                                        episode.actions.rename_blocker ??
                                        undefined
                                    "
                                    @select="openRename(episode)"
                                    ><Pencil />Rename episode</DropdownMenuItem
                                >
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    :disabled="
                                        !episode.actions.can_delete_media
                                    "
                                    :title="
                                        episode.actions.delete_media_blocker ??
                                        undefined
                                    "
                                    @select="openDelete('episode', episode)"
                                    ><Trash2 />Delete episode
                                    media</DropdownMenuItem
                                >
                                <p
                                    v-if="
                                        episode.actions.rename_blocker ||
                                        episode.actions.delete_media_blocker
                                    "
                                    class="px-2 py-1.5 text-xs text-muted-foreground"
                                >
                                    {{
                                        episode.actions.rename_blocker ??
                                        episode.actions.delete_media_blocker
                                    }}
                                </p>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </article>
                </div>
                <div
                    v-else
                    class="flex min-h-40 items-center justify-center p-6 text-sm text-muted-foreground"
                >
                    No episodes are listed for this season.
                </div>
            </div>
        </section>

        <Dialog v-model:open="renameOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader
                    ><DialogTitle>Rename episode</DialogTitle
                    ><DialogDescription
                        >TMDB keeps its original title. A custom title changes
                        display and the canonical path of an uploaded
                        episode.</DialogDescription
                    ></DialogHeader
                >
                <div v-if="selectedEpisode" class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="episode-custom-name">Custom title</Label
                        ><Input
                            id="episode-custom-name"
                            :model-value="renameForm.custom_name ?? ''"
                            :placeholder="selectedEpisode.tmdb_name"
                            @update:model-value="
                                renameForm.custom_name = String($event) || null
                            "
                        /><Button
                            v-if="selectedEpisode.custom_name"
                            variant="link"
                            class="h-auto justify-start p-0"
                            @click="renameForm.custom_name = null"
                            >Reset to TMDB title</Button
                        >
                    </div>
                    <div
                        v-if="previewRequest.processing"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <LoaderCircle class="size-4 animate-spin" />Checking
                        canonical path…
                    </div>
                    <div
                        v-else-if="renamePreview"
                        class="grid gap-2 rounded-lg border bg-muted/30 p-3 text-xs"
                    >
                        <p
                            v-if="renamePreview.blocker"
                            class="text-destructive"
                        >
                            {{ renamePreview.blocker }}
                        </p>
                        <template v-else-if="renamePreview.has_current_file"
                            ><p>
                                <span class="font-medium">From:</span>
                                <span class="font-mono break-all">{{
                                    renamePreview.source_relative_path
                                }}</span>
                            </p>
                            <p>
                                <span class="font-medium">To:</span>
                                <span class="font-mono break-all">{{
                                    renamePreview.destination_relative_path
                                }}</span>
                            </p></template
                        >
                        <p v-else>
                            The missing episode title will be used for its next
                            canonical upload path.
                        </p>
                    </div>
                    <label
                        class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                        ><Checkbox
                            :model-value="renameForm.rename_confirmed"
                            @update:model-value="
                                renameForm.rename_confirmed = $event === true
                            "
                        /><span
                            >I confirm this title and canonical path
                            change.</span
                        ></label
                    >
                    <p v-if="renameError" class="text-sm text-destructive">
                        {{ renameError }}
                    </p>
                </div>
                <DialogFooter
                    ><Button variant="outline" @click="renameOpen = false"
                        >Cancel</Button
                    ><Button
                        :disabled="
                            !renamePreview?.can_rename ||
                            !renameForm.rename_confirmed ||
                            renameForm.processing
                        "
                        @click="submitRename"
                        ><LoaderCircle
                            v-if="renameForm.processing"
                            class="size-4 animate-spin"
                        /><Pencil v-else class="size-4" />Confirm rename</Button
                    ></DialogFooter
                >
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader
                    ><div
                        class="mb-2 flex size-11 items-center justify-center rounded-full bg-destructive/10 text-destructive"
                    >
                        <AlertTriangle class="size-5" />
                    </div>
                    <DialogTitle>{{ deleteTitle }}</DialogTitle
                    ><DialogDescription
                        >Only exact claimed current video files are removed.
                        Artwork, subtitles, NFO files, and all other sidecars
                        remain untouched. This cannot be
                        undone.</DialogDescription
                    ></DialogHeader
                >
                <div class="grid gap-4">
                    <div v-if="deleteScope === 'series'" class="grid gap-2">
                        <Label for="show-confirmation-name"
                            >Type “{{ show.name }}”</Label
                        ><Input
                            id="show-confirmation-name"
                            v-model="deleteForm.confirmation_name"
                            autocomplete="off"
                        />
                    </div>
                    <label
                        class="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm"
                        ><Checkbox
                            :model-value="deleteForm.deletion_confirmed"
                            @update:model-value="
                                deleteForm.deletion_confirmed = $event === true
                            "
                        /><span
                            >I understand that
                            {{
                                deleteScope === 'series'
                                    ? 'the entire Show and its tracked media'
                                    : 'the selected tracked media'
                            }}
                            will be permanently deleted without a backup.</span
                        ></label
                    >
                    <p v-if="deletionError" class="text-sm text-destructive">
                        {{ deletionError }}
                    </p>
                </div>
                <DialogFooter
                    ><Button variant="outline" @click="deleteOpen = false"
                        >Cancel</Button
                    ><Button
                        variant="destructive"
                        :disabled="
                            !deleteForm.deletion_confirmed ||
                            (deleteScope === 'series' &&
                                deleteForm.confirmation_name !== show.name) ||
                            deleteForm.processing
                        "
                        @click="submitDelete"
                        ><LoaderCircle
                            v-if="deleteForm.processing"
                            class="size-4 animate-spin"
                        /><Trash2 v-else class="size-4" />Delete
                        permanently</Button
                    ></DialogFooter
                >
            </DialogContent>
        </Dialog>
    </div>
</template>
