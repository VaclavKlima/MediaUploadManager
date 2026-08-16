<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    Library,
    MoreVertical,
    Plus,
    Search,
    SlidersHorizontal,
    Upload,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import {
    index as seriesIndex,
    show as seriesShow,
    upload as seriesUpload,
} from '@/routes/series';
import type {
    SeriesCatalogFilters,
    SeriesCatalogPaginator,
} from '@/types/series';

const props = defineProps<{
    series: SeriesCatalogPaginator;
    filters: SeriesCatalogFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Shows', href: seriesIndex() },
        ],
    },
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const sort = ref(props.filters.sort);

const stateLabels = {
    complete: 'Complete',
    missing: 'Missing aired',
    empty: 'No media',
    in_progress: 'In progress',
} as const;

const stateClasses = {
    complete: 'border-emerald-500/30 bg-emerald-600/90 text-white',
    missing: 'border-amber-500/30 bg-amber-600/90 text-white',
    empty: 'border-muted-foreground/30 bg-background/90 text-foreground',
    in_progress: 'border-blue-500/30 bg-blue-600/90 text-white',
} as const;

function filterData(): Record<string, string | undefined> {
    return {
        search: search.value.trim() || undefined,
        status: status.value === 'all' ? undefined : status.value,
        sort: sort.value,
    };
}

function applyFilters(): void {
    router.get(seriesIndex.url(), filterData(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['series', 'filters'],
    });
}

const applySearch = useDebounceFn(applyFilters, 350);
watch(search, () => applySearch());

function clearFilters(): void {
    search.value = '';
    status.value = 'all';
    sort.value = 'recent';
    applyFilters();
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <Head title="Shows" />

        <header
            class="flex flex-col gap-4 rounded-2xl border border-sidebar-border/70 bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <Library class="size-5" />
                </span>
                <div class="min-w-0">
                    <h1
                        class="text-xl font-semibold tracking-tight md:text-2xl"
                    >
                        Shows
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ series.total.toLocaleString() }} confirmed
                        {{ series.total === 1 ? 'show' : 'shows' }}
                    </p>
                </div>
            </div>
            <Button as-child>
                <Link :href="seriesUpload()">
                    <Plus class="size-4" />
                    Upload show episodes
                </Link>
            </Button>
        </header>

        <section
            class="grid gap-3 rounded-xl border border-sidebar-border/70 bg-card p-3 shadow-sm sm:grid-cols-[minmax(0,1fr)_auto_auto] dark:border-sidebar-border"
            aria-label="Shows catalog filters"
        >
            <div class="relative min-w-0">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search title, original title, or TMDB ID"
                    aria-label="Search Shows"
                    class="pl-9"
                />
            </div>
            <Select v-model="status" @update:model-value="applyFilters">
                <SelectTrigger
                    class="w-full sm:w-44"
                    aria-label="Filter Shows by status"
                >
                    <SlidersHorizontal class="size-4" />
                    <SelectValue placeholder="All states" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All states</SelectItem>
                    <SelectItem value="complete">Complete</SelectItem>
                    <SelectItem value="missing">Missing aired</SelectItem>
                    <SelectItem value="empty">No media</SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="sort" @update:model-value="applyFilters">
                <SelectTrigger class="w-full sm:w-40" aria-label="Sort Shows">
                    <SelectValue placeholder="Sort Shows" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="recent">Recently updated</SelectItem>
                    <SelectItem value="title">Title A–Z</SelectItem>
                    <SelectItem value="coverage">Best coverage</SelectItem>
                </SelectContent>
            </Select>
        </section>

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            Showing {{ series.from ?? 0 }} through {{ series.to ?? 0 }} of
            {{ series.total }} Shows.
        </p>

        <section
            v-if="series.data.length"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6 2xl:grid-cols-8"
            aria-label="Confirmed Shows"
        >
            <article
                v-for="item in series.data"
                :key="item.id"
                class="group min-w-0 overflow-hidden rounded-lg border bg-card shadow-xs"
            >
                <div class="relative aspect-[2/3] overflow-hidden bg-muted">
                    <Link
                        :href="seriesShow(item.id)"
                        class="block size-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                        :aria-label="`View ${item.name}`"
                    >
                        <img
                            v-if="item.poster_url"
                            :src="item.poster_url"
                            :alt="`${item.name} poster`"
                            loading="lazy"
                            class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                        />
                        <span
                            v-else
                            class="flex size-full items-center justify-center bg-gradient-to-br from-muted to-muted/50 text-muted-foreground"
                        >
                            <Library class="size-10" />
                        </span>
                    </Link>
                    <Badge
                        variant="outline"
                        :class="[
                            'absolute top-1.5 left-1.5 max-w-[calc(100%-3rem)] gap-1 px-1.5 py-0.5 text-[10px] shadow-sm backdrop-blur-sm',
                            stateClasses[item.state],
                        ]"
                    >
                        <CheckCircle2
                            v-if="item.state === 'complete'"
                            class="size-2.5"
                        />
                        <AlertCircle
                            v-else-if="item.state === 'missing'"
                            class="size-2.5"
                        />
                        <span class="truncate">{{
                            stateLabels[item.state]
                        }}</span>
                    </Badge>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                size="icon-sm"
                                variant="secondary"
                                class="absolute top-1.5 right-1.5 shadow-sm"
                                :aria-label="`Actions for ${item.name}`"
                            >
                                <MoreVertical class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem as-child>
                                <Link :href="seriesShow(item.id)"
                                    >View details</Link
                                >
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child>
                                <Link
                                    :href="
                                        seriesUpload({
                                            query: { series: item.id },
                                        })
                                    "
                                >
                                    <Upload /> Upload episodes
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <Link
                    :href="seriesShow(item.id)"
                    class="block min-w-0 p-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                >
                    <span
                        class="line-clamp-2 block text-xs leading-4 font-semibold"
                    >
                        {{ item.name }}
                    </span>
                    <span
                        class="mt-0.5 block truncate text-[11px] text-muted-foreground"
                    >
                        {{ item.year ?? 'Year unknown' }} ·
                        {{ item.category === 'anime' ? 'Anime' : 'TV' }}
                    </span>
                    <span class="mt-1 flex flex-wrap gap-1">
                        <Badge
                            variant="secondary"
                            class="h-4 px-1.5 py-0 text-[9px]"
                        >
                            {{ item.coverage.seasons.available }}/{{
                                item.coverage.seasons.total
                            }}
                            seasons
                        </Badge>
                        <Badge
                            variant="secondary"
                            class="h-4 px-1.5 py-0 text-[9px]"
                        >
                            {{ item.coverage.episodes.available }}/{{
                                item.coverage.episodes.total
                            }}
                            episodes
                        </Badge>
                    </span>
                </Link>
            </article>
        </section>

        <section
            v-else
            class="flex min-h-72 flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-sidebar-border bg-card p-8 text-center"
        >
            <Library class="size-10 text-muted-foreground" />
            <div class="grid gap-1">
                <h2 class="font-semibold">No Shows found</h2>
                <p class="text-sm text-muted-foreground">
                    Try another search or status filter, or confirm a new Show.
                </p>
            </div>
            <Button
                v-if="search || status !== 'all' || sort !== 'recent'"
                variant="outline"
                @click="clearFilters"
            >
                Clear filters
            </Button>
        </section>

        <nav
            v-if="series.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3"
            aria-label="Shows pages"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ series.current_page }} of {{ series.last_page }}
            </p>
            <div class="flex gap-2">
                <Button
                    v-if="series.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="series.prev_page_url" preserve-scroll
                        >Previous</Link
                    >
                </Button>
                <Button v-else variant="outline" size="sm" disabled
                    >Previous</Button
                >
                <Button
                    v-if="series.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="series.next_page_url" preserve-scroll
                        >Next</Link
                    >
                </Button>
                <Button v-else variant="outline" size="sm" disabled
                    >Next</Button
                >
            </div>
        </nav>
    </div>
</template>
