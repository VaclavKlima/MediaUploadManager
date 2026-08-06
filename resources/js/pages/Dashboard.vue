<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CloudUpload,
    Film,
    FolderSearch2,
    HardDrive,
    ImageOff,
    Info,
    LoaderCircle,
    Search,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
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
import { dashboard } from '@/routes';

interface MovieSummary {
    tmdb_id: number;
    title: string;
    original_title: string | null;
    release_date: string | null;
    release_year: number | null;
    overview: string | null;
    poster_path: string | null;
    poster_url: string | null;
    original_language: string | null;
}

interface Genre {
    id: number;
    name: string;
}

interface MovieDetails extends MovieSummary {
    imdb_id: string | null;
    runtime: number | null;
    status: string | null;
    tagline: string | null;
    vote_average: number | null;
    vote_count: number | null;
    genres: Genre[];
}

interface ParsedFilename {
    filename: string;
    title: string;
    year: number | null;
}

interface SearchResponse {
    data: MovieSummary[];
    meta: {
        source: 'text' | 'filename';
        parsed?: ParsedFilename;
    };
}

interface DetailsResponse {
    data: MovieDetails;
}

interface ConfirmationResponse extends DetailsResponse {
    media_item_id: number;
    reused: boolean;
    has_current_primary: boolean;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const searchInput = ref('');
const results = ref<MovieSummary[]>([]);
const parsedFilename = ref<ParsedFilename | null>(null);
const selectedMovie = ref<MovieDetails | null>(null);
const confirmedMovie = ref<ConfirmationResponse | null>(null);
const detailsOpen = ref(false);
const errorMessage = ref('');

const textLookup = useHttp<{ query: string; year: string }, SearchResponse>({
    query: '',
    year: '',
});
const filenameLookup = useHttp<{ filename: string }, SearchResponse>({
    filename: '',
});
const detailsLookup = useHttp<Record<string, never>, DetailsResponse>({});
const confirmation = useHttp<{ tmdb_id: number }, ConfirmationResponse>({
    tmdb_id: 0,
});

const isLookingUp = computed(
    () =>
        textLookup.processing ||
        filenameLookup.processing ||
        detailsLookup.processing,
);

function cancelLookups(): void {
    textLookup.cancel();
    filenameLookup.cancel();
    detailsLookup.cancel();
}

function readError(data: string | undefined): string {
    if (!data) {
        return 'Movie lookup failed. Please try again.';
    }

    try {
        const payload = JSON.parse(data) as { message?: string };

        return payload.message ?? 'Movie lookup failed. Please try again.';
    } catch {
        return 'Movie lookup failed. Please try again.';
    }
}

async function runSmartSearch(): Promise<void> {
    const query = searchInput.value.trim();

    if (!query) {
        errorMessage.value = 'Enter a title, TMDB ID, or IMDb ID.';

        return;
    }

    cancelLookups();
    errorMessage.value = '';
    parsedFilename.value = null;

    try {
        if (/^tt\d{7,12}$/i.test(query)) {
            const response = await detailsLookup.get(
                MovieController.showImdb.url(query.toLowerCase()),
                {
                    onHttpException: (exception) => {
                        errorMessage.value = readError(exception.data);
                    },
                },
            );
            showDetails(response.data);

            return;
        }

        if (/^\d+$/.test(query)) {
            const response = await detailsLookup.get(
                MovieController.showTmdb.url(Number(query)),
                {
                    onHttpException: (exception) => {
                        errorMessage.value = readError(exception.data);
                    },
                },
            );
            showDetails(response.data);

            return;
        }

        textLookup.query = query;
        textLookup.year = '';
        const response = await textLookup.get(MovieController.search.url(), {
            onHttpException: (exception) => {
                errorMessage.value = readError(exception.data);
            },
        });
        results.value = response.data;
    } catch {
        // Cancellation and handled HTTP failures do not need a second message.
    }
}

async function selectFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    cancelLookups();
    errorMessage.value = '';
    filenameLookup.filename = file.name;

    try {
        const response = await filenameLookup.get(
            MovieController.suggestions.url(),
            {
                onHttpException: (exception) => {
                    errorMessage.value = readError(exception.data);
                },
            },
        );
        results.value = response.data;
        parsedFilename.value = response.meta.parsed ?? null;
        searchInput.value = parsedFilename.value?.title ?? file.name;
    } catch {
        // Cancellation and handled HTTP failures do not need a second message.
    }
}

async function inspectMovie(movie: MovieSummary): Promise<void> {
    cancelLookups();
    errorMessage.value = '';

    try {
        const response = await detailsLookup.get(
            MovieController.showTmdb.url(movie.tmdb_id),
            {
                onHttpException: (exception) => {
                    errorMessage.value = readError(exception.data);
                },
            },
        );
        showDetails(response.data);
    } catch {
        // Cancellation and handled HTTP failures do not need a second message.
    }
}

function showDetails(movie: MovieDetails): void {
    selectedMovie.value = movie;
    detailsOpen.value = true;
}

async function confirmMovie(): Promise<void> {
    if (!selectedMovie.value) {
        return;
    }

    errorMessage.value = '';
    confirmation.tmdb_id = selectedMovie.value.tmdb_id;

    try {
        confirmedMovie.value = await confirmation.post(
            MovieController.confirm.url(),
            {
                onHttpException: (exception) => {
                    errorMessage.value = readError(exception.data);
                },
            },
        );
        detailsOpen.value = false;
    } catch {
        // The safe server message is shown above.
    }
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <Head title="Identify movie" />

        <section
            class="overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
        >
            <div
                class="border-b bg-gradient-to-br from-primary/10 via-card to-card p-6 md:p-8"
            >
                <div class="flex max-w-3xl flex-col gap-3">
                    <Badge variant="secondary" class="w-fit">Step 1 of 3</Badge>
                    <h1
                        class="text-2xl font-semibold tracking-tight md:text-3xl"
                    >
                        Identify your movie
                    </h1>
                    <p
                        class="max-w-2xl text-sm leading-6 text-muted-foreground"
                    >
                        Choose a local file or search by title, TMDB ID, or IMDb
                        ID. Matching uses only the filename or search text—it is
                        not content recognition, and no file bytes are read.
                    </p>
                </div>
            </div>

            <div class="grid gap-6 p-6 md:p-8 lg:grid-cols-[1fr_auto_1fr]">
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-2 font-medium">
                        <FolderSearch2 class="size-4 text-primary" />
                        Start with a local filename
                    </div>
                    <label
                        for="movie-file"
                        class="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed bg-muted/30 p-5 text-center transition-colors hover:border-primary/50 hover:bg-primary/5"
                    >
                        <Film class="size-6 text-muted-foreground" />
                        <span class="text-sm font-medium"
                            >Choose movie file</span
                        >
                        <span class="text-xs text-muted-foreground">
                            Only the filename stays in browser memory
                        </span>
                    </label>
                    <input
                        id="movie-file"
                        type="file"
                        class="sr-only"
                        @change="selectFile"
                    />
                </div>

                <div class="flex items-center justify-center">
                    <span
                        class="rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground"
                        >or</span
                    >
                </div>

                <form
                    class="flex flex-col gap-3"
                    @submit.prevent="runSmartSearch"
                >
                    <label
                        for="movie-search"
                        class="flex items-center gap-2 font-medium"
                    >
                        <Search class="size-4 text-primary" />
                        Search for a movie
                    </label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id="movie-search"
                            v-model="searchInput"
                            class="h-11 flex-1"
                            placeholder="Dune, 438631, or tt1160419"
                            autocomplete="off"
                        />
                        <Button
                            type="submit"
                            class="h-11"
                            :disabled="isLookingUp"
                        >
                            <LoaderCircle
                                v-if="isLookingUp"
                                class="size-4 animate-spin"
                            />
                            <Search v-else class="size-4" />
                            Find movie
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Exact IMDb and numeric TMDB IDs open details directly.
                    </p>
                </form>
            </div>
        </section>

        <div
            v-if="errorMessage"
            role="alert"
            class="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ errorMessage }}
        </div>

        <section v-if="isLookingUp" aria-label="Loading movie results">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="index in 4"
                    :key="index"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <Skeleton class="aspect-[2/3] w-full rounded-none" />
                    <div class="flex flex-col gap-2 p-4">
                        <Skeleton class="h-5 w-3/4" />
                        <Skeleton class="h-4 w-1/3" />
                    </div>
                </div>
            </div>
        </section>

        <section v-else-if="results.length" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Movie matches</h2>
                    <p
                        v-if="parsedFilename"
                        class="text-sm text-muted-foreground"
                    >
                        Parsed “{{ parsedFilename.title }}”<span
                            v-if="parsedFilename.year"
                        >
                            ({{ parsedFilename.year }})</span
                        >
                        from {{ parsedFilename.filename }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        Select a result to inspect it before confirmation.
                    </p>
                </div>
                <Badge variant="outline">{{ results.length }} results</Badge>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <button
                    v-for="movie in results"
                    :key="movie.tmdb_id"
                    type="button"
                    class="group overflow-hidden rounded-xl border bg-card text-left shadow-sm transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="inspectMovie(movie)"
                >
                    <div class="aspect-[2/3] overflow-hidden bg-muted">
                        <img
                            v-if="movie.poster_url"
                            :src="movie.poster_url"
                            :alt="`${movie.title} poster`"
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="flex h-full flex-col items-center justify-center gap-2 text-muted-foreground"
                        >
                            <ImageOff class="size-8" />
                            <span class="text-xs">No artwork</span>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 p-4">
                        <div>
                            <h3 class="line-clamp-1 font-medium">
                                {{ movie.title }}
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                {{ movie.release_year ?? 'Year unknown' }} ·
                                TMDB
                                {{ movie.tmdb_id }}
                            </p>
                        </div>
                        <p
                            class="line-clamp-3 text-xs leading-5 text-muted-foreground"
                        >
                            {{ movie.overview || 'No overview is available.' }}
                        </p>
                    </div>
                </button>
            </div>
        </section>

        <section
            v-else-if="textLookup.wasSuccessful || filenameLookup.wasSuccessful"
            class="rounded-xl border border-dashed bg-muted/20 p-10 text-center"
        >
            <Film class="mx-auto size-8 text-muted-foreground" />
            <h2 class="mt-3 font-medium">No movie matches found</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Try a shorter title, a different filename, or an exact TMDB or
                IMDb ID.
            </p>
        </section>

        <section
            v-if="confirmedMovie"
            class="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-5 md:p-6"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <div
                    class="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-700 dark:text-emerald-300"
                >
                    <CheckCircle2 class="size-6" />
                </div>
                <div class="min-w-0 flex-1">
                    <p
                        class="text-sm font-medium text-emerald-800 dark:text-emerald-200"
                    >
                        Movie confirmed
                    </p>
                    <h2 class="truncate text-lg font-semibold">
                        {{ confirmedMovie.data.title }}
                        <span
                            v-if="confirmedMovie.data.release_year"
                            class="font-normal"
                        >
                            ({{ confirmedMovie.data.release_year }})
                        </span>
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            confirmedMovie.reused
                                ? 'Existing immutable identity reused'
                                : 'New immutable identity created'
                        }}
                        · Media item #{{ confirmedMovie.media_item_id }} ·
                        {{
                            confirmedMovie.has_current_primary
                                ? 'Current primary exists'
                                : 'No current primary yet'
                        }}
                    </p>
                </div>
            </div>
        </section>

        <section
            class="grid gap-4 md:grid-cols-3"
            aria-label="Upload workflow stages"
        >
            <div class="rounded-xl border border-primary/30 bg-primary/5 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 font-medium">
                        <Film class="size-4 text-primary" />
                        1. Identify
                    </div>
                    <Badge>Current</Badge>
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    Match and explicitly confirm a TMDB movie identity.
                </p>
            </div>
            <div
                class="rounded-xl border bg-muted/20 p-5 opacity-60"
                aria-disabled="true"
            >
                <div class="flex items-center gap-2 font-medium">
                    <HardDrive class="size-4" />
                    2. Choose storage
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    Available in MUM-006 after a movie is confirmed.
                </p>
            </div>
            <div
                class="rounded-xl border bg-muted/20 p-5 opacity-60"
                aria-disabled="true"
            >
                <div class="flex items-center gap-2 font-medium">
                    <CloudUpload class="size-4" />
                    3. Upload safely
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    Upload sessions and transfers are not active yet.
                </p>
            </div>
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-5"
            aria-label="Data credits"
        >
            <div class="flex items-center gap-2 text-sm font-medium">
                <Info class="size-4 text-muted-foreground" />
                Data credits
            </div>
            <img
                src="/images/tmdb-logo.svg"
                alt="The Movie Database (TMDB)"
                class="h-auto w-56 max-w-full"
            />
            <p class="text-xs leading-5 text-muted-foreground">
                This product uses the TMDB API but is not endorsed or certified
                by TMDB.
            </p>
        </section>

        <Dialog v-model:open="detailsOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <template v-if="selectedMovie">
                    <DialogHeader>
                        <DialogTitle>
                            {{ selectedMovie.title }}
                            <span
                                v-if="selectedMovie.release_year"
                                class="font-normal text-muted-foreground"
                            >
                                ({{ selectedMovie.release_year }})
                            </span>
                        </DialogTitle>
                        <DialogDescription>
                            Review the TMDB metadata before confirming this
                            immutable movie identity.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-5 sm:grid-cols-[180px_1fr]">
                        <div
                            class="aspect-[2/3] overflow-hidden rounded-lg bg-muted"
                        >
                            <img
                                v-if="selectedMovie.poster_url"
                                :src="selectedMovie.poster_url"
                                :alt="`${selectedMovie.title} poster`"
                                class="h-full w-full object-cover"
                            />
                            <div
                                v-else
                                class="flex h-full flex-col items-center justify-center gap-2 text-muted-foreground"
                            >
                                <ImageOff class="size-8" />
                                <span class="text-xs">No artwork</span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap gap-2">
                                <Badge variant="secondary"
                                    >TMDB {{ selectedMovie.tmdb_id }}</Badge
                                >
                                <Badge
                                    v-if="selectedMovie.imdb_id"
                                    variant="outline"
                                >
                                    {{ selectedMovie.imdb_id }}
                                </Badge>
                                <Badge
                                    v-if="selectedMovie.runtime"
                                    variant="outline"
                                >
                                    {{ selectedMovie.runtime }} min
                                </Badge>
                            </div>
                            <p
                                v-if="selectedMovie.tagline"
                                class="text-sm text-muted-foreground italic"
                            >
                                “{{ selectedMovie.tagline }}”
                            </p>
                            <p class="text-sm leading-6">
                                {{
                                    selectedMovie.overview ||
                                    'No overview is available.'
                                }}
                            </p>
                            <div
                                v-if="selectedMovie.genres.length"
                                class="flex flex-wrap gap-2"
                            >
                                <Badge
                                    v-for="genre in selectedMovie.genres"
                                    :key="genre.id"
                                    variant="secondary"
                                >
                                    {{ genre.name }}
                                </Badge>
                            </div>
                            <dl
                                class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                            >
                                <dt class="text-muted-foreground">
                                    Original title
                                </dt>
                                <dd>
                                    {{ selectedMovie.original_title || '—' }}
                                </dd>
                                <dt class="text-muted-foreground">
                                    Release date
                                </dt>
                                <dd>{{ selectedMovie.release_date || '—' }}</dd>
                                <dt class="text-muted-foreground">Language</dt>
                                <dd>
                                    {{
                                        selectedMovie.original_language?.toUpperCase() ||
                                        '—'
                                    }}
                                </dd>
                                <dt class="text-muted-foreground">
                                    TMDB rating
                                </dt>
                                <dd>
                                    {{
                                        selectedMovie.vote_average?.toFixed(
                                            1,
                                        ) || '—'
                                    }}<span
                                        v-if="selectedMovie.vote_count"
                                        class="text-muted-foreground"
                                    >
                                        / 10 ({{
                                            selectedMovie.vote_count
                                        }}
                                        votes)</span
                                    >
                                </dd>
                            </dl>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            :disabled="confirmation.processing"
                            @click="confirmMovie"
                        >
                            <LoaderCircle
                                v-if="confirmation.processing"
                                class="size-4 animate-spin"
                            />
                            <CheckCircle2 v-else class="size-4" />
                            Confirm movie
                        </Button>
                    </DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
