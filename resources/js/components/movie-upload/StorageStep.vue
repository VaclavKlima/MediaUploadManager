<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    HardDrive,
    LoaderCircle,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import type {
    ConfirmationResponse,
    DiskTarget,
    PathPreview,
} from '@/types/movie-upload';

const replacementConfirmed = defineModel<boolean>('replacementConfirmed', {
    required: true,
});

defineProps<{
    movie: ConfirmationResponse;
    preview: PathPreview | null;
    selectedDiskId: string;
    isChecking: boolean;
    isBusy: boolean;
    isHashing: boolean;
    isReserving: boolean;
    errorMessage: string;
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

function replacementMethodLabel(disk: DiskTarget): string | null {
    if (disk.replacement_method === 'atomic_same_path_swap') {
        return 'Atomic same-path replacement';
    }

    if (disk.replacement_method === 'finalize_then_delete') {
        return 'Finalize, then remove old file';
    }

    return null;
}
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <p class="text-xs font-medium text-primary">Step 3 of 5</p>
            <h2
                id="wizard-step-3"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Choose storage
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Choose where to store
                <span class="font-medium text-foreground">{{
                    movie.data.title
                }}</span
                >. Upload starts immediately after capacity is reserved.
            </p>
        </div>

        <div
            v-if="errorMessage"
            role="alert"
            class="flex items-start justify-between gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
        >
            <span class="flex min-w-0 items-start gap-2">
                <AlertTriangle class="mt-0.5 size-5 shrink-0" />
                <span>{{ errorMessage }}</span>
            </span>
            <button
                v-if="!preview && !isChecking"
                type="button"
                class="shrink-0 font-medium underline underline-offset-4"
                @click="$emit('retry')"
            >
                Try again
            </button>
        </div>

        <div
            v-if="isChecking"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            aria-live="polite"
        >
            <Skeleton v-for="index in 3" :key="index" class="h-36 rounded-xl" />
        </div>

        <template v-else-if="preview">
            <div
                v-if="
                    preview.can_replace_current_primary && preview.replaceable
                "
                class="flex flex-col gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-amber-950 dark:text-amber-100"
            >
                <div class="flex items-start gap-3">
                    <AlertTriangle class="mt-0.5 size-5 shrink-0" />
                    <div class="min-w-0 text-sm leading-6">
                        <h3 class="font-semibold">
                            Replacing the current primary is irreversible
                        </h3>
                        <p class="mt-1">
                            {{
                                preview.replaceable.disk.label ||
                                preview.replaceable.disk.id
                            }}
                            ·
                            <span class="font-mono break-all">{{
                                preview.replaceable.relative_path
                            }}</span>
                            ·
                            {{ formatBytes(preview.replaceable.size_bytes) }}
                        </p>
                    </div>
                </div>
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-amber-600/30 bg-background/50 p-3"
                >
                    <input
                        v-model="replacementConfirmed"
                        type="checkbox"
                        :disabled="isBusy"
                        class="mt-1 size-4 shrink-0 rounded border-amber-600 text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    />
                    <span class="text-sm leading-6 font-medium">
                        I understand the old file is permanently removed only
                        after the new file passes validation.
                    </span>
                </label>
            </div>

            <fieldset
                class="flex flex-col gap-3"
                :disabled="
                    isBusy ||
                    (preview.can_replace_current_primary &&
                        !replacementConfirmed)
                "
            >
                <legend class="sr-only">Available storage disks</legend>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <button
                        v-for="disk in preview.disks"
                        :key="disk.id"
                        type="button"
                        class="flex min-h-36 min-w-0 flex-col gap-3 rounded-xl border p-4 text-left transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
                        :class="[
                            disk.eligible &&
                            (!preview.can_replace_current_primary ||
                                replacementConfirmed)
                                ? 'hover:border-primary/50 hover:bg-primary/5'
                                : 'cursor-not-allowed bg-muted/20 opacity-60',
                            selectedDiskId === disk.id
                                ? 'border-primary bg-primary/5 ring-2 ring-primary/20'
                                : '',
                        ]"
                        :disabled="
                            !disk.eligible ||
                            isBusy ||
                            (preview.can_replace_current_primary &&
                                !replacementConfirmed)
                        "
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
                                Recommended
                            </Badge>
                            <Badge v-else-if="!disk.eligible" variant="outline"
                                >Unavailable</Badge
                            >
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
                                    usable after upload</span
                                >
                            </span>
                        </span>

                        <span
                            v-if="replacementMethodLabel(disk)"
                            class="text-xs font-medium text-amber-800 dark:text-amber-200"
                        >
                            {{ replacementMethodLabel(disk) }}
                        </span>
                        <span
                            v-else-if="disk.reasons.length"
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
                <p
                    v-if="isHashing || isReserving"
                    class="text-sm text-muted-foreground"
                    role="status"
                >
                    {{
                        isHashing
                            ? 'Fingerprinting the file…'
                            : 'Reserving capacity…'
                    }}
                </p>
            </fieldset>

            <details class="rounded-xl border bg-muted/10 p-4">
                <summary
                    class="cursor-pointer text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Storage details
                </summary>
                <div class="mt-4 flex flex-col gap-4 text-sm">
                    <dl class="grid gap-2 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-muted-foreground">
                                Exact relative destination
                            </dt>
                            <dd class="mt-1 font-mono break-all">
                                {{ preview.relative_path }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Source size
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatBytes(preview.declared_size) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Fingerprint window
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{
                                    formatBytes(
                                        preview.fingerprint_window_bytes,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
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
                                    :key="`details-${disk.id}`"
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
