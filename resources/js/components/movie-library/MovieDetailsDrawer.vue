<script setup lang="ts">
import { Film } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { MovieLibraryItem } from '@/types/movie-library';

const props = defineProps<{
    movie: MovieLibraryItem | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const formattedSize = computed(() => {
    const bytes = props.movie?.current_file?.size_bytes;

    if (bytes === undefined) {
        return null;
    }

    return new Intl.NumberFormat(undefined, {
        style: 'unit',
        unit: 'megabyte',
        maximumFractionDigits: 1,
    }).format(bytes / 1_048_576);
});

const finalizedDate = computed(() => {
    const value = props.movie?.current_file?.finalized_at;

    return value
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(
              new Date(value),
          )
        : null;
});
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
            <template v-if="movie">
                <SheetHeader class="text-left">
                    <SheetTitle>{{ movie.title }}</SheetTitle>
                    <SheetDescription>
                        Movie identity and tracked file details
                    </SheetDescription>
                </SheetHeader>

                <div class="grid gap-6 px-4 pb-6">
                    <div class="flex gap-4">
                        <div
                            class="flex aspect-[2/3] w-28 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                        >
                            <img
                                v-if="movie.poster_url"
                                :src="movie.poster_url"
                                :alt="`${movie.title} poster`"
                                class="size-full object-cover"
                            />
                            <Film v-else class="size-8 text-muted-foreground" />
                        </div>
                        <div class="min-w-0 space-y-2">
                            <Badge variant="outline">{{ movie.state }}</Badge>
                            <p class="text-sm text-muted-foreground">
                                {{ movie.release_year ?? 'Year unknown' }}
                            </p>
                            <p class="text-sm">TMDB {{ movie.tmdb_id }}</p>
                            <p v-if="movie.imdb_id" class="text-sm">
                                IMDb {{ movie.imdb_id }}
                            </p>
                        </div>
                    </div>

                    <dl class="grid gap-4 text-sm">
                        <div v-if="movie.original_title" class="grid gap-1">
                            <dt class="font-medium">Original title</dt>
                            <dd class="text-muted-foreground">
                                {{ movie.original_title }}
                            </dd>
                        </div>
                        <template v-if="movie.current_file">
                            <div class="grid gap-1">
                                <dt class="font-medium">Disk</dt>
                                <dd class="text-muted-foreground">
                                    {{
                                        movie.current_file.disk.label ??
                                        movie.current_file.disk.id
                                    }}
                                </dd>
                            </div>
                            <div class="grid min-w-0 gap-1">
                                <dt class="font-medium">Relative path</dt>
                                <dd
                                    class="font-mono text-xs break-all text-muted-foreground"
                                >
                                    {{ movie.current_file.relative_path }}
                                </dd>
                            </div>
                            <div class="grid gap-1">
                                <dt class="font-medium">Size</dt>
                                <dd class="text-muted-foreground">
                                    {{ formattedSize }} ·
                                    {{
                                        movie.current_file.size_bytes.toLocaleString()
                                    }}
                                    bytes
                                </dd>
                            </div>
                            <div
                                v-if="movie.current_file.owner"
                                class="grid gap-1"
                            >
                                <dt class="font-medium">Owner</dt>
                                <dd class="text-muted-foreground">
                                    {{ movie.current_file.owner.name }}
                                </dd>
                            </div>
                            <div v-if="finalizedDate" class="grid gap-1">
                                <dt class="font-medium">Finalized</dt>
                                <dd class="text-muted-foreground">
                                    {{ finalizedDate }}
                                </dd>
                            </div>
                        </template>
                        <div
                            v-else
                            class="rounded-lg border border-dashed p-4 text-muted-foreground"
                        >
                            No current primary file is attached to this
                            identity.
                        </div>
                        <div
                            v-if="
                                movie.deletion_blocker ||
                                movie.reidentification_blocker
                            "
                            class="grid gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4"
                        >
                            <dt class="font-medium">Blockers</dt>
                            <dd
                                v-if="movie.deletion_blocker"
                                class="text-muted-foreground"
                            >
                                {{ movie.deletion_blocker }}
                            </dd>
                            <dd
                                v-if="movie.reidentification_blocker"
                                class="text-muted-foreground"
                            >
                                {{ movie.reidentification_blocker }}
                            </dd>
                        </div>
                        <div
                            v-if="movie.reidentification?.status === 'failed'"
                            class="grid gap-1 rounded-lg border border-destructive/30 bg-destructive/10 p-4"
                        >
                            <dt class="font-medium text-destructive">
                                Failed identification change
                            </dt>
                            <dd class="text-sm text-destructive">
                                {{ movie.reidentification.error_detail }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
