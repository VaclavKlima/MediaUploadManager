<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Database,
    HardDrive,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type {
    ConfirmationResponse,
    PathPreview,
    UploadReservation,
} from '@/types/movie-upload';

const selectedDiskId = defineModel<string>('selectedDiskId', {
    required: true,
});
const replacementConfirmed = defineModel<boolean>('replacementConfirmed', {
    required: true,
});

const props = defineProps<{
    movie: ConfirmationResponse;
    preview: PathPreview;
    reservation: UploadReservation | null;
    isBusy: boolean;
    errorMessage: string;
}>();

const selectedDisk = computed(() =>
    props.preview.disks.find((disk) => disk.id === selectedDiskId.value),
);

const byteFormatter = new Intl.NumberFormat(undefined, {
    style: 'unit',
    unit: 'byte',
    notation: 'compact',
    maximumFractionDigits: 1,
});

function formatBytes(bytes: number | null): string {
    return bytes === null ? 'Unavailable' : byteFormatter.format(bytes);
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unavailable';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-primary">Step 4 of 5</p>
            <h2
                id="wizard-step-4"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Reserve capacity
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Choose an eligible destination for
                <span class="font-medium text-foreground">
                    {{ movie.data.title }}</span
                >. Capacity is checked again atomically before the reservation
                is created.
            </p>
        </div>

        <div
            v-if="errorMessage"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <p>{{ errorMessage }}</p>
        </div>

        <template v-if="reservation">
            <div
                class="flex items-start gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-emerald-900 dark:text-emerald-100"
                role="status"
            >
                <CheckCircle2 class="mt-0.5 size-6 shrink-0" />
                <div>
                    <h3 class="font-semibold">Capacity reserved</h3>
                    <p class="mt-1 text-sm opacity-90">
                        The session is pending. No movie bytes have been
                        uploaded.
                    </p>
                </div>
            </div>

            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border bg-muted/20 p-4">
                    <dt
                        class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                    >
                        <HardDrive class="size-4" /> Reserved disk
                    </dt>
                    <dd class="mt-2 font-semibold">
                        {{ reservation.disk.label || reservation.disk.id }}
                    </dd>
                    <dd class="font-mono text-xs text-muted-foreground">
                        {{ reservation.disk.id }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-muted/20 p-4">
                    <dt
                        class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                    >
                        <Clock3 class="size-4" /> Inactivity expiry
                    </dt>
                    <dd class="mt-2 font-semibold">
                        {{ formatDate(reservation.expires_at) }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        Pending · {{ reservation.declared_bytes }} bytes
                    </dd>
                </div>
                <div class="rounded-xl border bg-muted/20 p-4 sm:col-span-2">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Exact relative target
                    </dt>
                    <dd
                        class="mt-2 font-mono text-sm leading-6 break-all text-foreground"
                    >
                        {{ reservation.target_relative_path }}
                    </dd>
                </div>
            </dl>

            <div class="flex items-start gap-3 rounded-xl border p-4">
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-primary" />
                <p class="text-sm leading-6 text-muted-foreground">
                    The short-lived authorization token remains only in this
                    page's memory. Cancel this reservation before changing the
                    file, movie, or disk.
                </p>
            </div>
        </template>

        <template v-else>
            <fieldset :disabled="isBusy" class="flex flex-col gap-3">
                <legend class="font-semibold">Storage destination</legend>
                <p class="text-sm text-muted-foreground">
                    Projected capacity includes the safety reserve, all active
                    reservations, and this
                    {{ formatBytes(preview.declared_size) }}
                    file.
                </p>

                <div class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
                    <label
                        v-for="disk in preview.disks"
                        :key="disk.id"
                        class="relative flex min-w-0 flex-col gap-4 rounded-xl border p-4 transition-colors motion-reduce:transition-none"
                        :class="[
                            disk.eligible
                                ? 'cursor-pointer hover:border-primary/50'
                                : 'cursor-not-allowed bg-muted/20 opacity-65',
                            selectedDiskId === disk.id
                                ? 'border-primary bg-primary/5 ring-2 ring-primary/20'
                                : '',
                        ]"
                    >
                        <input
                            v-model="selectedDiskId"
                            type="radio"
                            name="reservation-disk"
                            :value="disk.id"
                            :disabled="!disk.eligible || isBusy"
                            class="peer sr-only"
                        />
                        <span
                            class="pointer-events-none absolute inset-0 rounded-xl peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2"
                        />

                        <span class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">
                                    {{ disk.label }}
                                </span>
                                <span
                                    class="block truncate font-mono text-xs text-muted-foreground"
                                >
                                    {{ disk.id }}
                                </span>
                            </span>
                            <Badge
                                v-if="preview.recommended_disk_id === disk.id"
                                variant="secondary"
                            >
                                Recommended
                            </Badge>
                            <Badge v-else-if="!disk.eligible" variant="outline">
                                Ineligible
                            </Badge>
                        </span>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Free space
                                </dt>
                                <dd class="font-medium">
                                    {{ formatBytes(disk.free_bytes) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Safety reserve
                                </dt>
                                <dd class="font-medium">
                                    {{ formatBytes(disk.safety_reserve_bytes) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Active reservations
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        formatBytes(disk.active_reserved_bytes)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Projected usable
                                </dt>
                                <dd
                                    class="font-medium"
                                    :class="
                                        disk.eligible
                                            ? 'text-emerald-700 dark:text-emerald-300'
                                            : ''
                                    "
                                >
                                    {{
                                        formatBytes(disk.projected_usable_bytes)
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <ul
                            v-if="disk.reasons.length"
                            class="flex flex-col gap-1 border-t pt-3 text-xs text-muted-foreground"
                        >
                            <li
                                v-for="reason in disk.reasons"
                                :key="reason.code"
                                class="flex items-start gap-2"
                            >
                                <AlertTriangle class="mt-0.5 size-3 shrink-0" />
                                {{ reason.message }}
                            </li>
                        </ul>
                        <p
                            v-else
                            class="flex items-center gap-2 border-t pt-3 text-xs text-emerald-700 dark:text-emerald-300"
                        >
                            <Database class="size-3" /> Eligible for reservation
                        </p>
                    </label>
                </div>
            </fieldset>

            <label
                v-if="
                    preview.can_replace_current_primary && preview.replaceable
                "
                class="flex cursor-pointer items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-amber-950 dark:text-amber-100"
            >
                <input
                    v-model="replacementConfirmed"
                    type="checkbox"
                    :disabled="isBusy"
                    class="mt-1 size-4 shrink-0 rounded border-amber-600 text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                />
                <span class="min-w-0 text-sm leading-6">
                    <span class="block font-semibold">
                        I understand this replacement is irreversible and keeps
                        no backup.
                    </span>
                    <span class="mt-1 block">
                        After the new file passes full validation, permanently
                        replace
                        <span class="font-medium">
                            {{
                                preview.replaceable.disk.label ||
                                preview.replaceable.disk.id
                            }}
                        </span>
                        ·
                        <span class="font-mono break-all">
                            {{ preview.replaceable.relative_path }}
                        </span>
                        · {{ formatBytes(preview.replaceable.size_bytes) }}.
                    </span>
                    <span class="mt-1 block font-medium">
                        {{
                            selectedDisk?.replacement_method ===
                            'atomic_same_path_swap'
                                ? 'Completion uses an atomic same-path swap.'
                                : 'Completion finalizes the new inode first, then deletes only the exact old file.'
                        }}
                    </span>
                </span>
            </label>
        </template>
    </section>
</template>
