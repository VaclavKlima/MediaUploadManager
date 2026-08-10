<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import { destroy } from '@/actions/App/Http/Controllers/MovieLibraryController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { MovieLibraryItem } from '@/types/movie-library';

const props = defineProps<{
    movie: MovieLibraryItem | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const form = useForm({
    deletion_confirmed: false,
});

const deletionError = computed(
    () => (form.errors as Record<string, string | undefined>).deletion,
);

watch(
    () => [props.movie?.id, props.open],
    () => {
        form.reset();
        form.clearErrors();
    },
);

function setOpen(open: boolean): void {
    if (!form.processing) {
        emit('update:open', open);
    }
}

function submit(): void {
    if (!props.movie || !form.deletion_confirmed || form.processing) {
        return;
    }

    form.delete(destroy.url(props.movie.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <div
                    class="mb-2 flex size-11 items-center justify-center rounded-full bg-destructive/10 text-destructive"
                >
                    <AlertTriangle class="size-5" />
                </div>
                <DialogTitle>
                    {{
                        movie?.state === 'deleting'
                            ? 'Retry permanent deletion'
                            : 'Permanently delete movie'
                    }}
                </DialogTitle>
                <DialogDescription>
                    This cannot be undone. The exact tracked primary and all
                    related application records will be removed without a
                    backup. Artwork, NFO files, subtitles, extras, and other
                    operator-managed sidecars are not deleted.
                </DialogDescription>
            </DialogHeader>

            <div v-if="movie" class="flex flex-col gap-4">
                <div class="rounded-lg border bg-muted/40 p-4 text-sm">
                    <p class="font-semibold text-foreground">
                        {{ movie.title }}
                        <span v-if="movie.release_year" class="font-normal">
                            ({{ movie.release_year }})
                        </span>
                    </p>
                    <dl
                        v-if="movie.current_file"
                        class="mt-3 grid gap-2 text-xs text-muted-foreground"
                    >
                        <div class="grid gap-0.5">
                            <dt class="font-medium text-foreground">Disk</dt>
                            <dd>
                                {{
                                    movie.current_file.disk.label ??
                                    movie.current_file.disk.id
                                }}
                            </dd>
                        </div>
                        <div class="grid min-w-0 gap-0.5">
                            <dt class="font-medium text-foreground">
                                Relative path
                            </dt>
                            <dd class="font-mono break-all">
                                {{ movie.current_file.relative_path }}
                            </dd>
                        </div>
                        <div class="grid gap-0.5">
                            <dt class="font-medium text-foreground">Size</dt>
                            <dd>
                                {{
                                    movie.current_file.size_bytes.toLocaleString()
                                }}
                                bytes
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="mt-2 text-xs text-muted-foreground">
                        This database movie has no current primary file. Its
                        related application history will be purged.
                    </p>
                </div>

                <form class="grid gap-2" @submit.prevent="submit">
                    <div
                        class="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4"
                    >
                        <Checkbox
                            id="movie-deletion-confirmation"
                            :model-value="form.deletion_confirmed"
                            :aria-invalid="
                                Boolean(
                                    form.errors.deletion_confirmed ||
                                    deletionError,
                                )
                            "
                            :disabled="form.processing"
                            class="mt-0.5 data-[state=checked]:border-destructive data-[state=checked]:bg-destructive"
                            @update:model-value="
                                form.deletion_confirmed = $event === true
                            "
                        />
                        <Label
                            for="movie-deletion-confirmation"
                            class="cursor-pointer text-sm leading-5 font-normal"
                        >
                            I understand that this permanently deletes
                            <span class="font-semibold text-foreground">{{
                                movie.title
                            }}</span>
                            and its exact tracked primary without a backup.
                        </Label>
                    </div>
                    <p
                        v-if="form.errors.deletion_confirmed"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ form.errors.deletion_confirmed }}
                    </p>
                    <p
                        v-if="deletionError"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ deletionError }}
                    </p>
                </form>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="setOpen(false)"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="!form.deletion_confirmed || form.processing"
                    @click="submit"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    <Trash2 v-else class="size-4" />
                    {{
                        form.processing
                            ? 'Deleting…'
                            : movie?.state === 'deleting'
                              ? 'Retry deletion'
                              : 'Delete permanently'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
