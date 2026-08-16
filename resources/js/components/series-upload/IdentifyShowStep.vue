<script setup lang="ts">
import { Check, ImageOff, LoaderCircle, Search, Tv2 } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import type { ParsedSeriesSource, SeriesSearchResult } from '@/types/series';

const searchInput = defineModel<string>('searchInput', { required: true });

defineProps<{
    sourceName: string;
    results: SeriesSearchResult[];
    selectedSeries: SeriesSearchResult | null;
    parsedSource: ParsedSeriesSource | null;
    category: 'tv' | 'anime';
    isLookingUp: boolean;
    isConfirming: boolean;
    lookupCompleted: boolean;
    errorMessage: string;
}>();

defineEmits<{
    search: [];
    select: [series: SeriesSearchResult];
    confirm: [];
    category: [category: 'tv' | 'anime'];
}>();

function overview(value: string | null): string {
    const trimmed = value?.trim();

    if (!trimmed) {
        return 'No overview is available.';
    }

    const characters = Array.from(trimmed);

    return characters.length <= 120
        ? trimmed
        : `${characters.slice(0, 119).join('').trimEnd()}…`;
}
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <p class="text-xs font-medium text-primary">Step 2 of 6</p>
            <h2
                id="wizard-step-2"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Choose show
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Select the match inferred from
                <span class="font-medium text-foreground">{{
                    sourceName
                }}</span>
                or search by show title or numeric TMDB ID.
            </p>
        </div>

        <div
            class="inline-flex w-fit rounded-lg border bg-muted/40 p-1"
            role="radiogroup"
            aria-label="Show category"
        >
            <Button
                type="button"
                size="sm"
                :variant="category === 'tv' ? 'default' : 'ghost'"
                role="radio"
                :aria-checked="category === 'tv'"
                :disabled="isConfirming"
                @click="$emit('category', 'tv')"
            >
                TV
            </Button>
            <Button
                type="button"
                size="sm"
                :variant="category === 'anime' ? 'default' : 'ghost'"
                role="radio"
                :aria-checked="category === 'anime'"
                :disabled="isConfirming"
                @click="$emit('category', 'anime')"
            >
                Anime
            </Button>
        </div>

        <form
            class="flex flex-col gap-2 sm:flex-row"
            @submit.prevent="$emit('search')"
        >
            <Input
                id="show-search"
                v-model="searchInput"
                class="h-11 flex-1"
                placeholder="Breaking Bad or 1396"
                autocomplete="off"
                aria-label="Search by show title or numeric TMDB ID"
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
            aria-label="Loading show results"
            aria-live="polite"
        >
            <div
                v-for="index in 6"
                :key="index"
                class="flex gap-3 rounded-xl border bg-card p-3"
            >
                <Skeleton class="h-32 w-22 shrink-0 rounded-lg" />
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
                    <h3 class="font-semibold">Matches</h3>
                    <p
                        v-if="parsedSource"
                        class="text-sm text-muted-foreground"
                    >
                        From “{{ parsedSource.title }}”<span
                            v-if="parsedSource.year"
                        >
                            ({{ parsedSource.year }})</span
                        >
                    </p>
                </div>
                <Badge variant="outline">{{ results.length }} results</Badge>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="list">
                <article
                    v-for="series in results"
                    :key="series.tmdb_id"
                    class="relative min-w-0 overflow-hidden rounded-xl border bg-card shadow-xs transition hover:border-primary/40 hover:shadow-sm motion-reduce:transition-none"
                    :class="
                        selectedSeries?.tmdb_id === series.tmdb_id
                            ? 'border-primary ring-2 ring-primary/20'
                            : ''
                    "
                    role="listitem"
                >
                    <button
                        type="button"
                        class="flex h-full w-full gap-3 p-3 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                        :aria-pressed="
                            selectedSeries?.tmdb_id === series.tmdb_id
                        "
                        :aria-label="`Choose ${series.name}${series.first_air_year ? ` (${series.first_air_year})` : ''}`"
                        @click="$emit('select', series)"
                    >
                        <span
                            class="flex h-32 w-22 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted transition motion-reduce:transition-none"
                            :class="
                                selectedSeries?.tmdb_id === series.tmdb_id
                                    ? 'opacity-40 blur-[1px]'
                                    : ''
                            "
                        >
                            <img
                                v-if="series.poster_url"
                                :src="series.poster_url"
                                :alt="`${series.name} poster`"
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
                                selectedSeries?.tmdb_id === series.tmdb_id
                                    ? 'opacity-40 blur-[1px]'
                                    : ''
                            "
                        >
                            <span class="block truncate font-medium">{{
                                series.name
                            }}</span>
                            <span
                                v-if="
                                    series.original_name &&
                                    series.original_name !== series.name
                                "
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ series.original_name }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ series.first_air_year ?? 'Year unknown' }} ·
                                TMDB
                                {{ series.tmdb_id }}
                            </span>
                            <span
                                class="mt-2 line-clamp-3 block text-xs leading-5 text-muted-foreground"
                            >
                                {{ overview(series.overview) }}
                            </span>
                        </span>
                    </button>

                    <Button
                        v-if="selectedSeries?.tmdb_id === series.tmdb_id"
                        type="button"
                        class="absolute inset-0 z-10 m-auto w-fit shadow-lg"
                        :disabled="isConfirming"
                        :aria-label="`Select ${series.name} and continue`"
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
            <Tv2 class="size-8 text-muted-foreground" />
            <h3 class="mt-3 font-medium">No show matches found</h3>
            <p class="mt-1 max-w-md text-sm text-muted-foreground">
                Try a shorter title or an exact numeric TMDB ID.
            </p>
        </div>
    </section>
</template>
