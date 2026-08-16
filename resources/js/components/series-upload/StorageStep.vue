<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    HardDrive,
    LoaderCircle,
    RefreshCw,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type {
    ConfirmedSeries,
    SeriesBatchPreview,
    SeriesPreviewDisk,
} from '@/types/series';

const props = defineProps<{
    series: ConfirmedSeries;
    preview: SeriesBatchPreview | null;
    selectedDiskId: string;
    isLoading: boolean;
    isBusy: boolean;
    errorMessage: string;
    fingerprintProgress: {
        completed: number;
        total: number;
        filename: string;
    };
}>();

defineEmits<{
    choose: [diskId: string];
    retry: [];
}>();

const byteFormatter = new Intl.NumberFormat(undefined, {
    style: 'unit',
    unit: 'byte',
    notation: 'compact',
    maximumFractionDigits: 1,
});

function formatBytes(bytes: number | null): string {
    return bytes === null ? 'Unavailable' : byteFormatter.format(bytes);
}

function diskActionLabel(disk: SeriesPreviewDisk): string {
    if (!disk.eligible) {
        return `${disk.label} is unavailable`;
    }

    if (props.series.home_disk_id !== null) {
        return `${disk.label}, assigned storage for this Show`;
    }

    return `Choose ${disk.label} and prepare the batch`;
}
</script>

<template>
    <section class="mx-auto flex w-full max-w-6xl flex-col gap-6 py-2">
        <div class="flex flex-col gap-2">
            <p class="text-xs font-medium text-primary">Step 4 of 6</p>
            <h2
                id="wizard-step-4"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Choose storage
            </h2>
            <p class="max-w-3xl text-sm leading-6 text-muted-foreground">
                <template v-if="series.home_disk_id">
                    This Show stays on its permanently assigned storage disk.
                    Preparation starts automatically when that disk is ready.
                </template>
                <template v-else>
                    Review the projected capacity, then explicitly choose an
                    eligible disk to fingerprint the files and start the batch.
                </template>
            </p>
        </div>

        <div
            v-if="errorMessage"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4"
            role="alert"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0 text-destructive" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Storage preparation needs attention</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ errorMessage }}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="isLoading || isBusy"
                @click="$emit('retry')"
            >
                <RefreshCw class="size-4" /> Retry
            </Button>
        </div>

        <div v-if="isLoading && !preview" class="grid gap-4 lg:grid-cols-3">
            <Skeleton class="h-36 rounded-2xl lg:col-span-3" />
            <Skeleton v-for="index in 3" :key="index" class="h-40 rounded-xl" />
        </div>

        <template v-else-if="preview">
            <div
                class="grid gap-4 rounded-2xl border bg-muted/10 p-5 sm:grid-cols-3"
            >
                <div>
                    <p class="text-xs text-muted-foreground">Show</p>
                    <p class="mt-1 truncate font-semibold">{{ series.name }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Batch</p>
                    <p class="mt-1 font-semibold">
                        {{ preview.items.length }}
                        {{
                            preview.items.length === 1 ? 'episode' : 'episodes'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Total size</p>
                    <p class="mt-1 font-semibold">
                        {{ formatBytes(preview.declared_bytes) }}
                    </p>
                </div>
            </div>

            <fieldset :disabled="isBusy">
                <legend class="mb-3 text-sm font-medium">
                    {{
                        series.home_disk_id
                            ? 'Assigned Shows storage'
                            : 'Eligible Shows storage disks'
                    }}
                </legend>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <button
                        v-for="disk in preview.disks"
                        :key="disk.id"
                        type="button"
                        class="flex min-h-40 min-w-0 flex-col gap-3 rounded-xl border p-4 text-left transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
                        :class="[
                            disk.eligible
                                ? 'hover:border-primary/50 hover:bg-primary/5'
                                : 'cursor-not-allowed bg-muted/20 opacity-65',
                            selectedDiskId === disk.id
                                ? 'border-primary bg-primary/5 ring-2 ring-primary/20'
                                : '',
                        ]"
                        :disabled="
                            !disk.eligible ||
                            isBusy ||
                            series.home_disk_id !== null
                        "
                        :aria-label="diskActionLabel(disk)"
                        @click="$emit('choose', disk.id)"
                    >
                        <span
                            class="flex w-full items-start justify-between gap-2"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{
                                    disk.label
                                }}</span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ disk.id }}</span
                                >
                            </span>
                            <Badge
                                v-if="preview.recommended_disk_id === disk.id"
                                variant="secondary"
                            >
                                {{
                                    series.home_disk_id
                                        ? 'Assigned'
                                        : 'Recommended'
                                }}
                            </Badge>
                            <Badge v-else-if="!disk.eligible" variant="outline">
                                Unavailable
                            </Badge>
                        </span>

                        <span class="flex items-center gap-2 text-sm">
                            <LoaderCircle
                                v-if="selectedDiskId === disk.id && isBusy"
                                class="size-4 text-primary motion-safe:animate-spin"
                            />
                            <HardDrive
                                v-else
                                class="size-4 text-muted-foreground"
                            />
                            <span>
                                <span class="font-medium">{{
                                    formatBytes(disk.projected_usable_bytes)
                                }}</span>
                                <span class="text-muted-foreground">
                                    usable after batch</span
                                >
                            </span>
                        </span>

                        <span
                            v-if="disk.reasons.length"
                            class="text-xs leading-5 text-muted-foreground"
                        >
                            {{ disk.reasons[0]?.message }}
                        </span>
                        <span
                            v-else
                            class="flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300"
                        >
                            <CheckCircle2 class="size-3.5" /> Ready
                        </span>
                    </button>
                </div>
            </fieldset>

            <p
                v-if="isBusy"
                class="text-sm text-muted-foreground"
                role="status"
            >
                <template v-if="fingerprintProgress.total">
                    Fingerprinting {{ fingerprintProgress.completed }} of
                    {{ fingerprintProgress.total }}:
                    {{ fingerprintProgress.filename }}
                </template>
                <template v-else>Preparing the upload batch…</template>
            </p>

            <Button
                v-else-if="!preview.can_start_batch"
                type="button"
                variant="outline"
                class="self-start"
                @click="$emit('retry')"
            >
                <RefreshCw class="size-4" /> Retry storage check
            </Button>

            <details class="rounded-xl border bg-muted/10 p-4">
                <summary
                    class="cursor-pointer text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Destinations and capacity details
                </summary>
                <div class="mt-4 flex flex-col gap-5">
                    <ul class="divide-y rounded-lg border text-sm">
                        <li
                            v-for="item in preview.items"
                            :key="item.series_episode_id"
                            class="grid gap-1 p-3 sm:grid-cols-[7rem_1fr_auto] sm:items-center"
                        >
                            <span class="font-medium">{{
                                item.episode_identity
                            }}</span>
                            <span class="min-w-0 font-mono text-xs break-all">{{
                                item.target_relative_path
                            }}</span>
                            <Badge v-if="item.replacement" variant="outline">
                                Replacement
                            </Badge>
                        </li>
                    </ul>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-2xl text-left text-xs">
                            <thead class="text-muted-foreground">
                                <tr>
                                    <th class="pb-2 font-medium">Disk</th>
                                    <th class="pb-2 font-medium">Free</th>
                                    <th class="pb-2 font-medium">
                                        Safety reserve
                                    </th>
                                    <th class="pb-2 font-medium">
                                        Active reservations
                                    </th>
                                    <th class="pb-2 font-medium">
                                        Projected usable
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="disk in preview.disks"
                                    :key="`capacity-${disk.id}`"
                                >
                                    <td class="py-2 font-medium">
                                        {{ disk.label }}
                                    </td>
                                    <td class="py-2">
                                        {{ formatBytes(disk.free_bytes) }}
                                    </td>
                                    <td class="py-2">
                                        {{
                                            formatBytes(
                                                disk.safety_reserve_bytes,
                                            )
                                        }}
                                    </td>
                                    <td class="py-2">
                                        {{
                                            formatBytes(
                                                disk.active_reserved_bytes,
                                            )
                                        }}
                                    </td>
                                    <td class="py-2">
                                        {{
                                            formatBytes(
                                                disk.projected_usable_bytes,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
        </template>
    </section>
</template>
