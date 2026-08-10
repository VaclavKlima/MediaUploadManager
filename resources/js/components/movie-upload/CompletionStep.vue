<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, Film, HardDrive, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { index as movieLibrary } from '@/routes/movies';
import type { UploadSession } from '@/types/movie-upload';

const props = defineProps<{
    session: UploadSession;
    movieTitle: string;
}>();

defineEmits<{
    another: [];
}>();

const byteFormatter = new Intl.NumberFormat(undefined, {
    style: 'unit',
    unit: 'byte',
    notation: 'compact',
    maximumFractionDigits: 1,
});

const primaryVideo = computed(
    () =>
        props.session.finalized?.video.find(
            (stream) => stream.disposition.default,
        ) ??
        props.session.finalized?.video[0] ??
        null,
);

function formatBytes(bytes: number): string {
    return byteFormatter.format(bytes);
}

function formatDuration(milliseconds: number): string {
    const totalMinutes = Math.round(milliseconds / 60_000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return hours > 0 ? `${hours} hr ${minutes} min` : `${minutes} min`;
}

function replacementMethod(
    method: 'atomic_same_path_swap' | 'finalize_then_delete',
): string {
    return method === 'atomic_same_path_swap'
        ? 'Atomic same-path replacement'
        : 'Finalized new file, then removed old file';
}
</script>

<template>
    <section class="mx-auto flex min-h-full w-full max-w-4xl flex-col gap-6">
        <div class="flex flex-col items-center gap-3 py-2 text-center">
            <span
                class="flex size-14 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-700 dark:text-emerald-300"
            >
                <CheckCircle2 class="size-8" />
            </span>
            <div>
                <p class="text-xs font-medium text-primary">Step 5 of 5</p>
                <h2
                    id="wizard-step-5"
                    tabindex="-1"
                    class="mt-1 text-2xl font-semibold tracking-tight outline-none"
                >
                    Upload complete
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ movieTitle }} is validated and ready in the movie
                    library.
                </p>
            </div>
        </div>

        <dl
            v-if="session.finalized"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
        >
            <div class="rounded-xl border bg-muted/20 p-4 sm:col-span-2">
                <dt
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Film class="size-4" /> Movie
                </dt>
                <dd class="mt-2 truncate font-semibold">{{ movieTitle }}</dd>
            </div>
            <div class="rounded-xl border bg-muted/20 p-4 sm:col-span-2">
                <dt
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <HardDrive class="size-4" /> Destination
                </dt>
                <dd class="mt-2 truncate font-semibold">
                    {{
                        session.finalized.disk.label ||
                        session.finalized.disk.id
                    }}
                </dd>
            </div>
            <div class="rounded-xl border bg-muted/20 p-4">
                <dt class="text-xs text-muted-foreground">File size</dt>
                <dd class="mt-2 font-semibold">
                    {{ formatBytes(session.finalized.size_bytes) }}
                </dd>
            </div>
            <div class="rounded-xl border bg-muted/20 p-4">
                <dt class="text-xs text-muted-foreground">Duration</dt>
                <dd class="mt-2 font-semibold">
                    {{
                        formatDuration(session.finalized.duration_milliseconds)
                    }}
                </dd>
            </div>
            <div class="rounded-xl border bg-muted/20 p-4 sm:col-span-2">
                <dt class="text-xs text-muted-foreground">
                    Primary resolution
                </dt>
                <dd class="mt-2 font-semibold">
                    {{
                        primaryVideo
                            ? `${primaryVideo.width}×${primaryVideo.height}`
                            : 'Unavailable'
                    }}
                </dd>
            </div>
        </dl>

        <details
            v-if="session.finalized"
            class="rounded-xl border bg-muted/10 p-4"
        >
            <summary
                class="cursor-pointer text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Technical details
            </summary>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">Exact path</dt>
                    <dd class="mt-1 font-mono break-all">
                        {{ session.finalized.relative_path }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Container</dt>
                    <dd class="mt-1 font-medium">
                        {{ session.finalized.container }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Video streams</dt>
                    <dd class="mt-1 font-medium">
                        {{
                            session.finalized.video
                                .map(
                                    (video) =>
                                        `${video.width}×${video.height} ${video.codec}`,
                                )
                                .join(' · ')
                        }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">Audio streams</dt>
                    <dd class="mt-1 font-medium">
                        {{
                            session.finalized.audio.length
                                ? session.finalized.audio
                                      .map(
                                          (audio) =>
                                              `${audio.codec}${audio.channels ? ` ${audio.channels}ch` : ''}${audio.language ? ` (${audio.language})` : ''}`,
                                      )
                                      .join(' · ')
                                : 'None reported'
                        }}
                    </dd>
                </div>
                <div v-if="session.replacement" class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        Replacement history
                    </dt>
                    <dd class="mt-1 leading-6">
                        {{ replacementMethod(session.replacement.method) }} ·
                        {{
                            session.replacement.disk.label ||
                            session.replacement.disk.id
                        }}
                        ·
                        <span class="font-mono break-all">{{
                            session.replacement.relative_path
                        }}</span>
                        ·
                        {{ formatBytes(session.replacement.size_bytes) }}
                    </dd>
                </div>
            </dl>
        </details>

        <div class="flex flex-col-reverse justify-center gap-2 sm:flex-row">
            <Button variant="outline" as-child>
                <Link :href="movieLibrary()">View movie library</Link>
            </Button>
            <Button type="button" @click="$emit('another')">
                <RotateCcw class="size-4" />
                Upload another movie
            </Button>
        </div>
    </section>
</template>
