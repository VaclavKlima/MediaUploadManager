<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Check, LoaderCircle } from '@lucide/vue';
import { computed, watch } from 'vue';
import { ref } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
import MovieReidentificationController from '@/actions/App/Http/Controllers/MovieReidentificationController';
import IdentifyMovieStep from '@/components/movie-upload/IdentifyMovieStep.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type {
    MovieLibraryItem,
    MovieReidentificationPreview,
} from '@/types/movie-library';
import type {
    DetailsResponse,
    MovieSummary,
    SearchResponse,
} from '@/types/movie-upload';

interface PreviewResponse {
    data: MovieReidentificationPreview;
}

const props = defineProps<{
    movie: MovieLibraryItem | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const searchInput = ref('');
const results = ref<MovieSummary[]>([]);
const selectedMovie = ref<MovieSummary | null>(null);
const preview = ref<MovieReidentificationPreview | null>(null);
const lookupCompleted = ref(false);
const lookupError = ref('');

const textLookup = useHttp<{ query: string; year: string }, SearchResponse>({
    query: '',
    year: '',
});
const detailsLookup = useHttp<Record<string, never>, DetailsResponse>({});
const previewRequest = useHttp<{ tmdb_id: number }, PreviewResponse>({
    tmdb_id: 0,
});
const form = useForm({
    tmdb_id: 0,
    reidentification_confirmed: false,
});

const isLookingUp = computed(
    () => textLookup.processing || detailsLookup.processing,
);
const reidentificationError = computed(
    () => (form.errors as Record<string, string | undefined>).reidentification,
);

watch(
    () => [props.movie?.id, props.open],
    () => {
        textLookup.cancel();
        detailsLookup.cancel();
        previewRequest.cancel();
        searchInput.value = props.movie?.title ?? '';
        results.value = [];
        selectedMovie.value = null;
        preview.value = null;
        lookupCompleted.value = false;
        lookupError.value = '';
        form.reset();
        form.clearErrors();
    },
);

function readError(data: string | undefined, fallback: string): string {
    if (!data) {
        return fallback;
    }

    try {
        const payload = JSON.parse(data) as { message?: string };

        return payload.message ?? fallback;
    } catch {
        return fallback;
    }
}

async function search(): Promise<void> {
    const query = searchInput.value.normalize('NFC').trim();

    if (!query) {
        lookupError.value = 'Enter a title, TMDB ID, or IMDb ID.';

        return;
    }

    textLookup.cancel();
    detailsLookup.cancel();
    results.value = [];
    selectedMovie.value = null;
    preview.value = null;
    lookupCompleted.value = false;
    lookupError.value = '';

    const onHttpException = (exception: { data?: string }) => {
        lookupError.value = readError(
            exception.data,
            'Movie lookup failed. Please try again.',
        );
    };

    try {
        if (/^tt\d{7,12}$/i.test(query)) {
            const response = await detailsLookup.get(
                MovieController.showImdb.url(query.toLowerCase()),
                { onHttpException },
            );
            results.value = [response.data];
        } else if (/^\d+$/.test(query)) {
            const response = await detailsLookup.get(
                MovieController.showTmdb.url(Number(query)),
                { onHttpException },
            );
            results.value = [response.data];
        } else {
            textLookup.query = query;
            textLookup.year = '';
            const response = await textLookup.get(
                MovieController.search.url(),
                {
                    onHttpException,
                },
            );
            results.value = response.data;
        }

        lookupCompleted.value = true;
    } catch {
        // The safe server error is displayed above.
    }
}

async function requestPreview(): Promise<void> {
    if (!props.movie || !selectedMovie.value) {
        return;
    }

    lookupError.value = '';
    previewRequest.tmdb_id = selectedMovie.value.tmdb_id;

    try {
        const response = await previewRequest.post(
            MovieReidentificationController.preview.url(props.movie.id),
            {
                onHttpException: (exception) => {
                    lookupError.value = readError(
                        exception.data,
                        'The identification change could not be previewed.',
                    );
                },
            },
        );
        preview.value = response.data;
        form.tmdb_id = selectedMovie.value.tmdb_id;
        form.reidentification_confirmed = false;
    } catch {
        // The safe server error is displayed above.
    }
}

function editSelection(): void {
    preview.value = null;
    form.reidentification_confirmed = false;
    form.clearErrors();
}

function setOpen(open: boolean): void {
    if (!form.processing) {
        emit('update:open', open);
    }
}

function submit(): void {
    if (
        !props.movie ||
        !preview.value?.eligible ||
        !form.reidentification_confirmed ||
        form.processing
    ) {
        return;
    }

    form.post(MovieReidentificationController.store.url(props.movie.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogScrollContent
            class="my-4 max-h-[calc(100dvh-2rem)] overflow-y-auto sm:my-8 sm:max-h-[calc(100dvh-4rem)] sm:max-w-4xl"
        >
            <template v-if="movie">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            movie.reidentification &&
                            !movie.reidentification.completed_at
                                ? 'Retry identification change'
                                : 'Change movie identification'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Select the correct TMDB identity, review the exact
                        same-disk path change, then confirm it.
                    </DialogDescription>
                </DialogHeader>

                <IdentifyMovieStep
                    v-if="!preview"
                    v-model:search-input="searchInput"
                    :source-filename="movie.title"
                    :results="results"
                    :selected-movie="selectedMovie"
                    :parsed-filename="null"
                    :is-looking-up="isLookingUp"
                    :is-confirming="previewRequest.processing"
                    :lookup-completed="lookupCompleted"
                    :error-message="lookupError"
                    step-label="Identification"
                    heading="Choose the correct movie"
                    @search="search"
                    @select="selectedMovie = $event"
                    @confirm="requestPreview"
                />

                <div v-else class="grid gap-5">
                    <Button
                        type="button"
                        variant="ghost"
                        class="w-fit"
                        :disabled="form.processing"
                        @click="editSelection"
                    >
                        <ArrowLeft />
                        Choose another identity
                    </Button>

                    <div class="grid gap-3 md:grid-cols-2">
                        <section class="rounded-xl border bg-muted/30 p-4">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Current identity
                            </p>
                            <h3 class="mt-1 font-semibold">
                                {{ preview.current_identity.title }}
                                <span
                                    v-if="preview.current_identity.release_year"
                                >
                                    ({{
                                        preview.current_identity.release_year
                                    }})
                                </span>
                            </h3>
                            <p class="mt-1 text-xs text-muted-foreground">
                                TMDB {{ preview.current_identity.tmdb_id }}
                                <template
                                    v-if="preview.current_identity.imdb_id"
                                >
                                    · IMDb
                                    {{ preview.current_identity.imdb_id }}
                                </template>
                            </p>
                        </section>
                        <section
                            class="rounded-xl border border-primary/30 bg-primary/5 p-4"
                        >
                            <p class="text-xs font-medium text-primary">
                                Proposed identity
                            </p>
                            <h3 class="mt-1 font-semibold">
                                {{ preview.proposed_identity.title }}
                                <span
                                    v-if="
                                        preview.proposed_identity.release_year
                                    "
                                >
                                    ({{
                                        preview.proposed_identity.release_year
                                    }})
                                </span>
                            </h3>
                            <p class="mt-1 text-xs text-muted-foreground">
                                TMDB {{ preview.proposed_identity.tmdb_id }}
                                <template
                                    v-if="preview.proposed_identity.imdb_id"
                                >
                                    · IMDb
                                    {{ preview.proposed_identity.imdb_id }}
                                </template>
                            </p>
                        </section>
                    </div>

                    <dl class="grid gap-3 rounded-xl border p-4 text-sm">
                        <div class="grid gap-1">
                            <dt class="font-medium">Disk</dt>
                            <dd class="text-muted-foreground">
                                {{
                                    preview.disk
                                        ? (preview.disk.label ??
                                          preview.disk.id)
                                        : 'Database only'
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="preview.size_bytes !== null"
                            class="grid gap-1"
                        >
                            <dt class="font-medium">Size</dt>
                            <dd class="text-muted-foreground">
                                {{ preview.size_bytes.toLocaleString() }} bytes
                            </dd>
                        </div>
                        <div
                            v-if="preview.current_relative_path"
                            class="grid gap-1"
                        >
                            <dt class="font-medium">Current tracked path</dt>
                            <dd
                                class="font-mono text-xs break-all text-muted-foreground"
                            >
                                {{ preview.current_relative_path }}
                            </dd>
                        </div>
                        <div
                            v-if="preview.proposed_relative_path"
                            class="grid gap-1"
                        >
                            <dt class="font-medium">Proposed canonical path</dt>
                            <dd
                                class="font-mono text-xs break-all text-muted-foreground"
                            >
                                {{ preview.proposed_relative_path }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="preview.blocker"
                        role="alert"
                        class="flex gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p class="font-medium">Change blocked</p>
                            <p>{{ preview.blocker.message }}</p>
                        </div>
                    </div>

                    <div
                        v-if="preview.retry?.error_detail"
                        class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm"
                    >
                        Previous attempt: {{ preview.retry.error_detail }}
                    </div>

                    <div
                        class="flex items-start gap-3 rounded-xl border p-4"
                        :class="!preview.eligible ? 'opacity-50' : ''"
                    >
                        <Checkbox
                            id="movie-reidentification-confirmation"
                            :model-value="form.reidentification_confirmed"
                            :disabled="!preview.eligible || form.processing"
                            class="mt-0.5"
                            @update:model-value="
                                form.reidentification_confirmed =
                                    $event === true
                            "
                        />
                        <Label
                            for="movie-reidentification-confirmation"
                            class="text-sm leading-5 font-normal"
                        >
                            I confirm this identity and canonical path change.
                            The tracked movie file stays on the same disk;
                            artwork, subtitles, NFO files, and other sidecars
                            are left untouched.
                        </Label>
                    </div>

                    <p
                        v-if="
                            form.errors.reidentification_confirmed ||
                            reidentificationError
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{
                            form.errors.reidentification_confirmed ??
                            reidentificationError
                        }}
                    </p>
                </div>

                <DialogFooter v-if="preview">
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
                        :disabled="
                            !preview.eligible ||
                            !form.reidentification_confirmed ||
                            form.processing
                        "
                        @click="submit"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <Check v-else />
                        {{ form.processing ? 'Changing…' : 'Confirm change' }}
                    </Button>
                </DialogFooter>
            </template>
        </DialogScrollContent>
    </Dialog>
</template>
