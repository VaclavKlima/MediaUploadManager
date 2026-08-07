<script setup lang="ts">
import { CheckCircle2, ImageOff, LoaderCircle } from '@lucide/vue';
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
import type { MovieDetails } from '@/types/movie-upload';

const open = defineModel<boolean>('open', { required: true });

defineProps<{
    movie: MovieDetails | null;
    isConfirming: boolean;
}>();

defineEmits<{
    confirm: [];
}>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <template v-if="movie">
                <DialogHeader>
                    <DialogTitle>
                        {{ movie.title }}
                        <span
                            v-if="movie.release_year"
                            class="font-normal text-muted-foreground"
                        >
                            ({{ movie.release_year }})
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
                            v-if="movie.poster_url"
                            :src="movie.poster_url"
                            :alt="`${movie.title} poster`"
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
                                >TMDB {{ movie.tmdb_id }}</Badge
                            >
                            <Badge v-if="movie.imdb_id" variant="outline">
                                {{ movie.imdb_id }}
                            </Badge>
                            <Badge v-if="movie.runtime" variant="outline">
                                {{ movie.runtime }} min
                            </Badge>
                        </div>
                        <p
                            v-if="movie.tagline"
                            class="text-sm text-muted-foreground italic"
                        >
                            “{{ movie.tagline }}”
                        </p>
                        <p class="text-sm leading-6">
                            {{ movie.overview || 'No overview is available.' }}
                        </p>
                        <div
                            v-if="movie.genres.length"
                            class="flex flex-wrap gap-2"
                        >
                            <Badge
                                v-for="genre in movie.genres"
                                :key="genre.id"
                                variant="secondary"
                            >
                                {{ genre.name }}
                            </Badge>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-muted-foreground">
                                Original title
                            </dt>
                            <dd>{{ movie.original_title || '—' }}</dd>
                            <dt class="text-muted-foreground">Release date</dt>
                            <dd>{{ movie.release_date || '—' }}</dd>
                            <dt class="text-muted-foreground">Language</dt>
                            <dd>
                                {{
                                    movie.original_language?.toUpperCase() ||
                                    '—'
                                }}
                            </dd>
                            <dt class="text-muted-foreground">TMDB rating</dt>
                            <dd>
                                {{ movie.vote_average?.toFixed(1) || '—'
                                }}<span
                                    v-if="movie.vote_count"
                                    class="text-muted-foreground"
                                >
                                    / 10 ({{ movie.vote_count }} votes)</span
                                >
                            </dd>
                        </dl>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        :disabled="isConfirming"
                        @click="$emit('confirm')"
                    >
                        <LoaderCircle
                            v-if="isConfirming"
                            class="size-4 motion-safe:animate-spin"
                        />
                        <CheckCircle2 v-else class="size-4" />
                        Confirm movie
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
