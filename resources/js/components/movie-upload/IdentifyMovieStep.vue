<script setup lang="ts">
import { Check, Film, ImageOff, LoaderCircle, Search } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import type { MovieSummary, ParsedFilename } from '@/types/movie-upload';

const searchInput = defineModel<string>('searchInput', { required: true });
const overviewCharacterLimit = 80;

defineProps<{
    sourceFilename: string;
    results: MovieSummary[];
    selectedMovie: MovieSummary | null;
    parsedFilename: ParsedFilename | null;
    isLookingUp: boolean;
    isConfirming: boolean;
    lookupCompleted: boolean;
    errorMessage: string;
    stepLabel?: string;
    heading?: string;
}>();

defineEmits<{
    search: [];
    select: [movie: MovieSummary];
    confirm: [];
}>();

function limitOverview(overview: string | null): string {
    const trimmedOverview = overview?.trim();

    if (!trimmedOverview) {
        return 'No overview is available.';
    }

    const characters = Array.from(trimmedOverview);

    if (characters.length <= overviewCharacterLimit) {
        return trimmedOverview;
    }

    return `${characters
        .slice(0, overviewCharacterLimit - 1)
        .join('')
        .trimEnd()}…`;
}
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <p class="text-xs font-medium text-primary">
                {{ stepLabel ?? 'Step 2 of 5' }}
            </p>
            <h2
                id="wizard-step-2"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                {{ heading ?? 'Choose movie' }}
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Select the match for
                <span class="font-medium text-foreground">{{
                    sourceFilename
                }}</span>
                or search by title, TMDB ID, or IMDb ID.
            </p>
        </div>

        <form
            class="flex flex-col gap-2 sm:flex-row"
            @submit.prevent="$emit('search')"
        >
            <Input
                id="movie-search"
                v-model="searchInput"
                class="h-11 flex-1"
                placeholder="Dune, 438631, or tt1160419"
                autocomplete="off"
                aria-label="Search by movie title, TMDB ID, or IMDb ID"
            />
            <Button
                type="submit"
                class="h-11"
                :disabled="isLookingUp || isConfirming"
            >
                <LoaderCircle
                    v-if="isLookingUp"
                    class="size-4 motion-safe:animate-spin"
                />
                <Search v-else class="size-4" />
                Search
            </Button>
        </form>

        <div
            v-if="errorMessage"
            role="alert"
            class="rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ errorMessage }}
        </div>

        <div
            v-if="isLookingUp"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            aria-label="Loading movie results"
            aria-live="polite"
        >
            <div
                v-for="index in 6"
                :key="index"
                class="flex gap-3 rounded-xl border bg-card p-3"
            >
                <Skeleton class="h-28 w-20 shrink-0 rounded-lg" />
                <div class="flex flex-1 flex-col gap-2 py-1">
                    <Skeleton class="h-5 w-3/4" />
                    <Skeleton class="h-4 w-1/3" />
                    <Skeleton class="h-4 w-full" />
                </div>
            </div>
        </div>

        <template v-else-if="results.length">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Matches</h3>
                    <p
                        v-if="parsedFilename"
                        class="text-sm text-muted-foreground"
                    >
                        From “{{ parsedFilename.title }}”<span
                            v-if="parsedFilename.year"
                        >
                            ({{ parsedFilename.year }})</span
                        >
                    </p>
                </div>
                <Badge variant="outline">{{ results.length }} results</Badge>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="list">
                <article
                    v-for="movie in results"
                    :key="movie.tmdb_id"
                    class="relative min-w-0 overflow-hidden rounded-xl border bg-card shadow-xs transition hover:border-primary/40 hover:shadow-sm motion-reduce:transition-none"
                    :class="
                        selectedMovie?.tmdb_id === movie.tmdb_id
                            ? 'border-primary ring-2 ring-primary/20'
                            : ''
                    "
                    role="listitem"
                >
                    <button
                        type="button"
                        class="flex w-full gap-3 p-3 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                        :aria-pressed="selectedMovie?.tmdb_id === movie.tmdb_id"
                        :aria-label="`Choose ${movie.title}${movie.release_year ? ` (${movie.release_year})` : ''}`"
                        @click="$emit('select', movie)"
                    >
                        <span
                            class="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted transition motion-reduce:transition-none"
                            :class="
                                selectedMovie?.tmdb_id === movie.tmdb_id
                                    ? 'opacity-40 blur-[1px]'
                                    : ''
                            "
                        >
                            <img
                                v-if="movie.poster_url"
                                :src="movie.poster_url"
                                :alt="`${movie.title} poster`"
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
                                selectedMovie?.tmdb_id === movie.tmdb_id
                                    ? 'opacity-40 blur-[1px]'
                                    : ''
                            "
                        >
                            <span class="block truncate font-medium">{{
                                movie.title
                            }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ movie.release_year ?? 'Year unknown' }} ·
                                TMDB {{ movie.tmdb_id }}
                            </span>
                            <span
                                class="mt-2 line-clamp-2 block text-xs leading-5 text-muted-foreground"
                            >
                                {{ limitOverview(movie.overview) }}
                            </span>
                        </span>
                    </button>

                    <Button
                        v-if="selectedMovie?.tmdb_id === movie.tmdb_id"
                        type="button"
                        class="absolute inset-0 z-10 m-auto w-fit shadow-lg"
                        :disabled="isConfirming"
                        :aria-label="`Select ${movie.title} and continue`"
                        @click="$emit('confirm')"
                    >
                        <LoaderCircle
                            v-if="isConfirming"
                            class="size-4 motion-safe:animate-spin"
                        />
                        <Check v-else class="size-4" />
                        {{ isConfirming ? 'Selecting…' : 'Select' }}
                    </Button>
                </article>
            </div>
        </template>

        <div
            v-else-if="lookupCompleted"
            class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-dashed bg-muted/20 p-8 text-center"
        >
            <Film class="size-8 text-muted-foreground" />
            <h3 class="mt-3 font-medium">No movie matches found</h3>
            <p class="mt-1 max-w-md text-sm text-muted-foreground">
                Try a shorter title or an exact TMDB or IMDb ID.
            </p>
        </div>
    </section>
</template>
