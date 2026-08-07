<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Film, Plus, Search, SlidersHorizontal } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import { index as movieLibraryIndex } from '@/actions/App/Http/Controllers/MovieLibraryController';
import MovieDeleteDialog from '@/components/movie-library/MovieDeleteDialog.vue';
import MovieLibraryCard from '@/components/movie-library/MovieLibraryCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { upload as movieUpload } from '@/routes/movies';
import type {
    MovieLibraryFilters,
    MovieLibraryItem,
    MovieLibraryPaginator,
} from '@/types/movie-library';

const props = defineProps<{
    movies: MovieLibraryPaginator;
    filters: MovieLibraryFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Movies',
                href: movieLibraryIndex(),
            },
        ],
    },
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const sort = ref(props.filters.sort);
const selectedMovie = ref<MovieLibraryItem | null>(null);
const deleteDialogOpen = ref(false);

function filterData(): Record<string, string | undefined> {
    return {
        search: search.value.trim() || undefined,
        status: status.value === 'all' ? undefined : status.value,
        sort: sort.value,
    };
}

function applyFilters(): void {
    router.get(movieLibraryIndex.url(), filterData(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['movies', 'filters'],
    });
}

const applySearch = useDebounceFn(applyFilters, 350);

watch(search, () => applySearch());

function clearFilters(): void {
    search.value = '';
    status.value = 'all';
    sort.value = 'newest';
    applyFilters();
}

function openDelete(movie: MovieLibraryItem): void {
    selectedMovie.value = movie;
    deleteDialogOpen.value = true;
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <Head title="Movies" />

        <header
            class="flex flex-col gap-4 rounded-2xl border border-sidebar-border/70 bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <Film class="size-5" />
                </span>
                <div class="min-w-0">
                    <h1
                        class="text-xl font-semibold tracking-tight md:text-2xl"
                    >
                        Movie library
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ movies.total.toLocaleString() }} tracked
                        {{ movies.total === 1 ? 'movie' : 'movies' }}
                    </p>
                </div>
            </div>
            <Button as-child>
                <Link :href="movieUpload()">
                    <Plus class="size-4" />
                    Upload movie
                </Link>
            </Button>
        </header>

        <section
            class="grid gap-3 rounded-xl border border-sidebar-border/70 bg-card p-3 shadow-sm sm:grid-cols-[minmax(0,1fr)_auto_auto] dark:border-sidebar-border"
            aria-label="Movie library filters"
        >
            <div class="relative min-w-0">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search tracked movies"
                    aria-label="Search movies"
                    class="pl-9"
                />
            </div>

            <Select v-model="status" @update:model-value="applyFilters">
                <SelectTrigger
                    class="w-full sm:w-44"
                    aria-label="Filter by status"
                >
                    <SlidersHorizontal class="size-4" />
                    <SelectValue placeholder="All states" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All states</SelectItem>
                    <SelectItem value="available">Available</SelectItem>
                    <SelectItem value="in_progress">In progress</SelectItem>
                    <SelectItem value="failed">Needs attention</SelectItem>
                    <SelectItem value="orphaned">No primary</SelectItem>
                    <SelectItem value="deleting">Deleting</SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="sort" @update:model-value="applyFilters">
                <SelectTrigger class="w-full sm:w-40" aria-label="Sort movies">
                    <SelectValue placeholder="Sort movies" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="newest">Newest first</SelectItem>
                    <SelectItem value="title">Title A–Z</SelectItem>
                </SelectContent>
            </Select>
        </section>

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            Showing {{ movies.from ?? 0 }} through {{ movies.to ?? 0 }} of
            {{ movies.total }} movies.
        </p>

        <section
            v-if="movies.data.length > 0"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5"
            aria-label="Tracked movies"
        >
            <MovieLibraryCard
                v-for="movie in movies.data"
                :key="movie.id"
                :movie="movie"
                @delete="openDelete"
            />
        </section>

        <section
            v-else
            class="flex min-h-72 flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-sidebar-border bg-card p-8 text-center"
        >
            <span
                class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <Film class="size-6" />
            </span>
            <div class="grid gap-1">
                <h2 class="font-semibold">No movies found</h2>
                <p class="max-w-md text-sm text-muted-foreground">
                    Try another search or state filter, or upload a new movie.
                </p>
            </div>
            <Button
                v-if="search || status !== 'all' || sort !== 'newest'"
                type="button"
                variant="outline"
                @click="clearFilters"
            >
                Clear filters
            </Button>
        </section>

        <nav
            v-if="movies.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3"
            aria-label="Movie pages"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ movies.current_page }} of {{ movies.last_page }}
            </p>
            <div class="flex items-center gap-2">
                <Button
                    v-if="movies.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="movies.prev_page_url" preserve-scroll>
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Previous
                </Button>
                <Button
                    v-if="movies.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="movies.next_page_url" preserve-scroll>
                        Next
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Next
                </Button>
            </div>
        </nav>

        <MovieDeleteDialog
            v-model:open="deleteDialogOpen"
            :movie="selectedMovie"
        />
    </div>
</template>
