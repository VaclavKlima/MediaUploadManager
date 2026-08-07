<script setup lang="ts">
import {
    AlertCircle,
    Clock3,
    Film,
    HardDrive,
    LoaderCircle,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    MovieLibraryItem,
    MovieLibraryState,
} from '@/types/movie-library';

const props = defineProps<{
    movie: MovieLibraryItem;
}>();

const emit = defineEmits<{
    delete: [movie: MovieLibraryItem];
}>();

const stateLabels: Record<MovieLibraryState, string> = {
    available: 'Available',
    in_progress: 'In progress',
    failed: 'Needs attention',
    orphaned: 'No primary',
    deleting: 'Deleting',
};

const stateClasses: Record<MovieLibraryState, string> = {
    available:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    in_progress:
        'border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-300',
    failed: 'border-destructive/30 bg-destructive/10 text-destructive',
    orphaned:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    deleting:
        'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300',
};

const finalizedDate = computed(() => {
    if (!props.movie.current_file) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(props.movie.current_file.finalized_at));
});

const formattedSize = computed(() => {
    const bytes = props.movie.current_file?.size_bytes;

    if (bytes === undefined) {
        return null;
    }

    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString(undefined, {
        maximumFractionDigits: unit === 0 ? 0 : 1,
    })} ${units[unit]}`;
});
</script>

<template>
    <article
        class="group flex min-w-0 flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border"
    >
        <div class="relative aspect-[2/3] overflow-hidden bg-muted">
            <img
                v-if="movie.poster_url"
                :src="movie.poster_url"
                :alt="`${movie.title} poster`"
                loading="lazy"
                class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
            />
            <div
                v-else
                class="flex size-full items-center justify-center bg-gradient-to-br from-muted to-muted/50 text-muted-foreground"
            >
                <Film class="size-12" />
            </div>
            <Badge
                variant="outline"
                :class="[
                    'absolute top-2 left-2 gap-1 shadow-sm backdrop-blur-sm',
                    stateClasses[movie.state],
                ]"
            >
                <LoaderCircle
                    v-if="movie.state === 'deleting'"
                    class="size-3 animate-spin"
                />
                <AlertCircle
                    v-else-if="movie.state === 'failed'"
                    class="size-3"
                />
                {{ stateLabels[movie.state] }}
            </Badge>
        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-3 p-3">
            <div class="min-w-0">
                <h2 class="line-clamp-2 text-sm leading-5 font-semibold">
                    {{ movie.title }}
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ movie.release_year ?? 'Year unknown' }} · TMDB
                    {{ movie.tmdb_id }}
                </p>
            </div>

            <div
                v-if="movie.current_file"
                class="grid gap-1.5 text-xs text-muted-foreground"
            >
                <p class="flex min-w-0 items-center gap-1.5">
                    <HardDrive class="size-3.5 shrink-0" />
                    <span class="truncate">
                        {{
                            movie.current_file.disk.label ??
                            movie.current_file.disk.id
                        }}
                        · {{ formattedSize }}
                    </span>
                </p>
                <p
                    v-if="movie.current_file.owner"
                    class="flex min-w-0 items-center gap-1.5"
                >
                    <UserRound class="size-3.5 shrink-0" />
                    <span class="truncate">{{
                        movie.current_file.owner.name
                    }}</span>
                </p>
                <p v-if="finalizedDate" class="flex items-center gap-1.5">
                    <Clock3 class="size-3.5 shrink-0" />
                    {{ finalizedDate }}
                </p>
            </div>
            <p v-else class="text-xs leading-5 text-muted-foreground">
                No application-tracked current primary is attached to this movie
                identity.
            </p>

            <div class="mt-auto grid gap-1.5">
                <Button
                    type="button"
                    size="sm"
                    :variant="movie.can_delete ? 'destructive' : 'outline'"
                    :disabled="!movie.can_delete"
                    class="w-full"
                    @click="emit('delete', movie)"
                >
                    <Trash2 class="size-3.5" />
                    {{
                        movie.state === 'deleting'
                            ? 'Retry deletion'
                            : 'Delete movie'
                    }}
                </Button>
                <p
                    v-if="movie.deletion_blocker"
                    class="text-[11px] leading-4 text-muted-foreground"
                >
                    {{ movie.deletion_blocker }}
                </p>
            </div>
        </div>
    </article>
</template>
