<script setup lang="ts">
import {
    AlertCircle,
    Film,
    Info,
    LoaderCircle,
    MoreVertical,
    RefreshCw,
    Trash2,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    MovieLibraryItem,
    MovieLibraryState,
} from '@/types/movie-library';

defineProps<{
    movie: MovieLibraryItem;
}>();

const emit = defineEmits<{
    details: [movie: MovieLibraryItem];
    reidentify: [movie: MovieLibraryItem];
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
        'border-emerald-500/30 bg-emerald-500/90 text-white dark:bg-emerald-700/90',
    in_progress: 'border-blue-500/30 bg-blue-600/90 text-white',
    failed: 'border-destructive/30 bg-destructive/90 text-destructive-foreground',
    orphaned: 'border-amber-500/30 bg-amber-600/90 text-white',
    deleting: 'border-violet-500/30 bg-violet-600/90 text-white',
};
</script>

<template>
    <article
        class="group min-w-0 overflow-hidden rounded-lg border bg-card shadow-xs"
    >
        <div class="relative aspect-[2/3] overflow-hidden bg-muted">
            <button
                type="button"
                class="size-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                :aria-label="`View details for ${movie.title}`"
                @click="emit('details', movie)"
            >
                <img
                    v-if="movie.poster_url"
                    :src="movie.poster_url"
                    :alt="`${movie.title} poster`"
                    loading="lazy"
                    class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                />
                <span
                    v-else
                    class="flex size-full items-center justify-center bg-gradient-to-br from-muted to-muted/50 text-muted-foreground"
                >
                    <Film class="size-10" />
                </span>
            </button>

            <Badge
                variant="outline"
                :class="[
                    'absolute top-1.5 left-1.5 max-w-[calc(100%-3rem)] gap-1 px-1.5 py-0.5 text-[10px] shadow-sm backdrop-blur-sm',
                    stateClasses[movie.state],
                ]"
            >
                <LoaderCircle
                    v-if="movie.state === 'deleting'"
                    class="size-2.5 animate-spin"
                />
                <AlertCircle
                    v-else-if="movie.state === 'failed'"
                    class="size-2.5"
                />
                <span class="truncate">{{ stateLabels[movie.state] }}</span>
            </Badge>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="secondary"
                        class="absolute top-1.5 right-1.5 shadow-sm"
                        :aria-label="`Actions for ${movie.title}`"
                    >
                        <MoreVertical class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuItem @select="emit('details', movie)">
                        <Info />
                        View details
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="movie.can_reidentify"
                        @select="emit('reidentify', movie)"
                    >
                        <RefreshCw />
                        {{
                            movie.reidentification &&
                            !movie.reidentification.completed_at
                                ? 'Retry identification change'
                                : 'Change identification'
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        :disabled="!movie.can_delete"
                        @select="emit('delete', movie)"
                    >
                        <Trash2 />
                        {{
                            movie.state === 'deleting'
                                ? 'Retry deletion'
                                : 'Delete movie'
                        }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <button
            type="button"
            class="block w-full min-w-0 p-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            @click="emit('details', movie)"
        >
            <span class="line-clamp-2 block text-xs leading-4 font-semibold">
                {{ movie.title }}
            </span>
            <span
                class="mt-0.5 block truncate text-[11px] text-muted-foreground"
            >
                {{ movie.release_year ?? 'Year unknown' }} · TMDB
                {{ movie.tmdb_id }}
            </span>
        </button>
    </article>
</template>
