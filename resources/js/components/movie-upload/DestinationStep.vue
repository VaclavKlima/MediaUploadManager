<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    FolderSearch2,
    HardDrive,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import type { ConfirmationResponse, PathPreview } from '@/types/movie-upload';

defineProps<{
    movie: ConfirmationResponse;
    sourceFilename: string;
    preview: PathPreview | null;
    isChecking: boolean;
    errorMessage: string;
}>();
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-primary">Step 3 of 5</p>
            <h2
                id="wizard-step-3"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Check the destination
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Review the exact global Jellyfin path and every configured disk
                before capacity can be reserved.
            </p>
        </div>

        <div
            class="flex flex-col gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4 sm:flex-row sm:items-center"
        >
            <CheckCircle2
                class="size-6 shrink-0 text-emerald-700 dark:text-emerald-300"
            />
            <div class="min-w-0 flex-1">
                <p
                    class="text-xs font-medium text-emerald-800 dark:text-emerald-200"
                >
                    Confirmed identity
                </p>
                <p class="truncate font-semibold">
                    {{ movie.data.title }}
                    <span v-if="movie.data.release_year" class="font-normal">
                        ({{ movie.data.release_year }})
                    </span>
                </p>
                <p class="text-xs text-muted-foreground">
                    TMDB {{ movie.data.tmdb_id }} · {{ sourceFilename }}
                </p>
            </div>
        </div>

        <div
            v-if="isChecking"
            class="flex flex-col gap-4"
            aria-label="Checking movie destination"
            aria-live="polite"
        >
            <Skeleton class="h-24 w-full rounded-xl" />
            <Skeleton class="h-20 w-full rounded-xl" />
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Skeleton
                    v-for="index in 3"
                    :key="index"
                    class="h-28 rounded-xl"
                />
            </div>
        </div>

        <div
            v-else-if="errorMessage"
            role="alert"
            class="flex min-h-52 flex-col items-center justify-center gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-8 text-center text-destructive"
        >
            <AlertTriangle class="size-8" />
            <div>
                <h3 class="font-medium">Destination check unavailable</h3>
                <p class="mt-1 text-sm">{{ errorMessage }}</p>
            </div>
        </div>

        <template v-else-if="preview">
            <div
                class="flex items-start gap-3 rounded-xl border p-4"
                :class="
                    preview.can_start_new_upload
                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-900 dark:text-emerald-100'
                        : preview.can_replace_current_primary
                          ? 'border-amber-500/40 bg-amber-500/10 text-amber-950 dark:text-amber-100'
                          : 'border-destructive/30 bg-destructive/10 text-destructive'
                "
                role="status"
            >
                <CheckCircle2
                    v-if="preview.can_start_new_upload"
                    class="mt-0.5 size-5 shrink-0"
                />
                <AlertTriangle v-else class="mt-0.5 size-5 shrink-0" />
                <div class="min-w-0">
                    <h3 class="font-medium">
                        {{
                            preview.can_start_new_upload
                                ? 'Movie is absent from every checked target'
                                : preview.can_replace_current_primary
                                  ? 'Tracked current primary is safely replaceable'
                                  : 'A new ordinary upload is blocked globally'
                        }}
                    </h3>
                    <p class="mt-1 text-sm opacity-90">
                        {{
                            preview.can_start_new_upload
                                ? 'At least one configured disk has a clear canonical target.'
                                : preview.can_replace_current_primary
                                  ? 'The old file remains untouched until the new upload passes full media validation. Successful replacement is irreversible and keeps no backup.'
                                  : 'Resolve the existing movie or upload state before choosing storage.'
                        }}
                    </p>
                    <ul
                        v-if="preview.blockers.length"
                        class="mt-3 flex list-disc flex-col gap-1 pl-5 text-sm"
                    >
                        <li
                            v-for="blocker in preview.blockers"
                            :key="`${blocker.code}-${blocker.disk?.id ?? 'global'}`"
                        >
                            {{ blocker.message }}
                            <span v-if="blocker.disk">
                                ({{ blocker.disk.label || blocker.disk.id }})
                            </span>
                        </li>
                    </ul>
                    <dl
                        v-if="
                            preview.can_replace_current_primary &&
                            preview.replaceable
                        "
                        class="mt-3 grid gap-1 text-sm"
                    >
                        <div>
                            <dt class="inline font-medium">Old disk:</dt>
                            <dd class="ml-1 inline">
                                {{
                                    preview.replaceable.disk.label ||
                                    preview.replaceable.disk.id
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="inline font-medium">Old path:</dt>
                            <dd class="ml-1 inline font-mono break-all">
                                {{ preview.replaceable.relative_path }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="rounded-xl border bg-muted/20 p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    Exact relative Jellyfin destination
                </p>
                <code
                    class="mt-2 block font-mono text-sm leading-6 break-all text-foreground"
                    >{{ preview.relative_path }}</code
                >
                <p class="mt-2 text-xs text-muted-foreground">
                    Extension: {{ preview.extension }}
                </p>
            </div>

            <div class="flex flex-col gap-3">
                <div>
                    <h3 class="font-medium">Configured disk targets</h3>
                    <p class="text-sm text-muted-foreground">
                        Local observations are shown below; any global conflict
                        blocks every disk.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="disk in preview.disks"
                        :key="disk.id"
                        class="flex flex-col gap-3 rounded-xl border p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h4 class="truncate font-medium">
                                    {{ disk.label }}
                                </h4>
                                <p
                                    class="truncate font-mono text-xs text-muted-foreground"
                                >
                                    {{ disk.id }}
                                </p>
                            </div>
                            <Badge
                                :variant="
                                    disk.status === 'conflict'
                                        ? 'destructive'
                                        : ['clear', 'replaceable'].includes(
                                                disk.status,
                                            )
                                          ? 'secondary'
                                          : 'outline'
                                "
                            >
                                {{ disk.status }}
                            </Badge>
                        </div>
                        <p
                            v-if="
                                ['clear', 'replaceable'].includes(disk.status)
                            "
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                disk.status === 'replaceable'
                                    ? 'Eligible for confirmed replacement.'
                                    : 'No matching target or local database state was found.'
                            }}
                        </p>
                        <ul v-else class="flex flex-col gap-1 text-sm">
                            <li
                                v-for="reason in disk.reasons"
                                :key="reason.code"
                                class="text-muted-foreground"
                            >
                                {{ reason.message }}
                            </li>
                        </ul>
                    </article>
                </div>
            </div>
        </template>

        <div
            v-else
            class="flex min-h-52 flex-col items-center justify-center gap-3 rounded-xl border border-dashed bg-muted/20 p-8 text-center"
        >
            <FolderSearch2 class="size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                Waiting for a destination preview.
            </p>
        </div>

        <div class="grid gap-3" aria-label="Upload stage status">
            <div
                class="rounded-xl border bg-muted/20 p-4 opacity-60"
                aria-disabled="true"
            >
                <div class="flex items-center gap-2 font-medium">
                    <HardDrive class="size-4" />
                    5. Upload movie bytes
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Available after an eligible destination is reserved.
                </p>
            </div>
        </div>
    </section>
</template>
