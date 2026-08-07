<script setup lang="ts">
import { Film, ImageOff, LoaderCircle, Search } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import type { MovieSummary, ParsedFilename } from '@/types/movie-upload';

const searchInput = defineModel<string>('searchInput', { required: true });

defineProps<{
    sourceFilename: string;
    results: MovieSummary[];
    parsedFilename: ParsedFilename | null;
    isLookingUp: boolean;
    lookupCompleted: boolean;
    errorMessage: string;
}>();

defineEmits<{
    search: [];
    inspect: [movie: MovieSummary];
}>();
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-primary">Step 2 of 5</p>
            <h2
                id="wizard-step-2"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Identify the movie
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Ranked suggestions come from
                <span class="font-medium text-foreground">{{
                    sourceFilename
                }}</span
                >. Search manually by title, TMDB ID, or IMDb ID if needed.
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
                aria-describedby="movie-search-help"
            />
            <Button type="submit" class="h-11" :disabled="isLookingUp">
                <LoaderCircle
                    v-if="isLookingUp"
                    class="size-4 motion-safe:animate-spin"
                />
                <Search v-else class="size-4" />
                Find movie
            </Button>
        </form>
        <p id="movie-search-help" class="text-xs text-muted-foreground">
            Unicode titles are normalized before using the same ranked fallback
            pipeline as filename suggestions.
        </p>

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
                    <Skeleton class="h-4 w-4/5" />
                </div>
            </div>
        </div>

        <template v-else-if="results.length">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Ranked suggestions</h3>
                    <p
                        v-if="parsedFilename"
                        class="text-sm text-muted-foreground"
                    >
                        Parsed “{{ parsedFilename.title }}”<span
                            v-if="parsedFilename.year"
                        >
                            ({{ parsedFilename.year }})</span
                        >
                    </p>
                </div>
                <Badge variant="outline">{{ results.length }} results</Badge>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="movie in results"
                    :key="movie.tmdb_id"
                    type="button"
                    class="group flex min-w-0 gap-3 rounded-xl border bg-card p-3 text-left shadow-xs transition hover:border-primary/40 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
                    @click="$emit('inspect', movie)"
                >
                    <div
                        class="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                    >
                        <img
                            v-if="movie.poster_url"
                            :src="movie.poster_url"
                            :alt="`${movie.title} poster`"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <ImageOff v-else class="size-6 text-muted-foreground" />
                    </div>
                    <div class="min-w-0 py-1">
                        <h4 class="truncate font-medium">{{ movie.title }}</h4>
                        <p class="text-xs text-muted-foreground">
                            {{ movie.release_year ?? 'Year unknown' }} · TMDB
                            {{ movie.tmdb_id }}
                        </p>
                        <p
                            class="mt-2 line-clamp-3 text-xs leading-5 text-muted-foreground"
                        >
                            {{ movie.overview || 'No overview is available.' }}
                        </p>
                    </div>
                </button>
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
