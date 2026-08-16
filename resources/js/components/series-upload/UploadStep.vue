<script setup lang="ts">
import {
    AlertTriangle,
    Check,
    CirclePause,
    LoaderCircle,
    RotateCcw,
    SkipForward,
} from '@lucide/vue';
import { computed } from 'vue';
import { ref } from 'vue';
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
import type { SeriesQueueItem } from '@/composables/useSeriesUploadWizard';
import type { SeriesBatch } from '@/types/series';
import type { UploadConnectionState } from '@/types/upload-transport';

const props = defineProps<{
    batch: SeriesBatch;
    items: SeriesQueueItem[];
    activeItem: SeriesQueueItem | null;
    overallConfirmedBytes: number;
    resolvedCount: number;
    connectionState: UploadConnectionState;
    speedBytesPerSecond: number;
    etaSeconds: number | null;
    errorMessage: string;
}>();

const emit = defineEmits<{
    pause: [];
    retryTransfer: [];
    retryValidation: [];
    retryReconciliation: [];
    skip: [];
}>();
const skipDialogOpen = ref(false);

const overallPercentage = computed(() =>
    props.items.length > 0
        ? Math.floor((props.resolvedCount / props.items.length) * 100)
        : 0,
);
const itemPercentage = computed(() => {
    const item = props.activeItem;

    return item && item.batchItem.declared_bytes > 0
        ? Math.min(
              100,
              Math.floor(
                  (item.confirmedBytes / item.batchItem.declared_bytes) * 100,
              ),
          )
        : 0;
});

const numberFormatter = new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 1,
});

function formatSpeed(bytes: number): string {
    return bytes > 0
        ? `${numberFormatter.format(bytes / 1_000_000)} MB/s`
        : 'Calculating…';
}

function formatBytes(bytes: number): string {
    if (bytes >= 1_000_000_000) {
        return `${numberFormatter.format(bytes / 1_000_000_000)} GB`;
    }

    return `${numberFormatter.format(bytes / 1_000_000)} MB`;
}

function confirmSkip(): void {
    skipDialogOpen.value = false;
    emit('skip');
}

function formatEta(seconds: number | null): string {
    if (seconds === null) {
        return 'Calculating…';
    }

    if (seconds < 60) {
        return `${Math.ceil(seconds)} sec`;
    }

    return `${Math.ceil(seconds / 60)} min`;
}

function statusLabel(item: SeriesQueueItem): string {
    if (item.status === 'completed') {
        return 'Uploaded';
    }

    if (item.status === 'cancelled') {
        return 'Skipped';
    }

    if (item.status === 'processing') {
        return 'Validating';
    }

    if (item.status === 'uploading') {
        return 'Uploading';
    }

    if (item.status === 'failed' || item.status === 'expired') {
        return 'Stopped';
    }

    if (item === props.activeItem) {
        return 'Preparing';
    }

    return 'Queued';
}
</script>

<template>
    <section class="mx-auto flex w-full max-w-5xl flex-col gap-6 py-2">
        <div class="flex flex-col gap-2">
            <p class="text-xs font-medium text-primary">Step 5 of 6</p>
            <h2
                id="wizard-step-5"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Upload and validate
            </h2>
            <p class="text-sm text-muted-foreground">
                Episodes transfer in order. Each episode must finish validation
                or be explicitly skipped before the next one begins.
            </p>
        </div>

        <div class="rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium">Overall batch progress</p>
                    <p class="text-xs text-muted-foreground">
                        {{ batch.home_disk.label ?? batch.home_disk.id }} ·
                        {{ resolvedCount }} of {{ items.length }} episodes
                        resolved
                    </p>
                </div>
                <span class="text-2xl font-semibold"
                    >{{ overallPercentage }}%</span
                >
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ formatBytes(overallConfirmedBytes) }} transferred of
                {{ formatBytes(batch.declared_bytes) }} admitted bytes
            </p>
            <div
                class="mt-4 h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-label="Overall batch progress"
                :aria-valuenow="overallPercentage"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                    :style="{ width: `${overallPercentage}%` }"
                />
            </div>
        </div>

        <div v-if="activeItem" class="rounded-2xl border p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Current episode</p>
                    <h3 class="truncate text-lg font-semibold">
                        {{ activeItem.batchItem.episode.identity }} ·
                        {{ activeItem.batchItem.episode.title }}
                    </h3>
                    <p class="truncate text-xs text-muted-foreground">
                        {{
                            activeItem.file?.name ??
                            activeItem.batchItem.source_basename
                        }}
                    </p>
                </div>
                <Badge>{{ statusLabel(activeItem) }}</Badge>
            </div>
            <div
                class="mt-4 h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-label="Current episode progress"
                :aria-valuenow="itemPercentage"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                    :style="{ width: `${itemPercentage}%` }"
                />
            </div>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Episode progress
                    </dt>
                    <dd class="font-medium">{{ itemPercentage }}%</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Speed</dt>
                    <dd class="font-medium">
                        {{ formatSpeed(speedBytesPerSecond) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">ETA</dt>
                    <dd class="font-medium">{{ formatEta(etaSeconds) }}</dd>
                </div>
            </dl>

            <div
                v-if="errorMessage"
                class="mt-4 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3"
                role="alert"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <p class="text-sm">{{ errorMessage }}</p>
            </div>
            <p
                v-if="
                    activeItem.status === 'failed' &&
                    !activeItem.batchItem.actions.retry &&
                    !activeItem.batchItem.actions.cancel
                "
                class="mt-3 text-sm text-muted-foreground"
            >
                This replacement has irreversible claimed state. It must be
                resolved on the server before later episodes can continue.
            </p>

            <div class="mt-5 flex flex-wrap gap-2">
                <Button
                    v-if="resolvedCount === items.length && errorMessage"
                    type="button"
                    @click="$emit('retryReconciliation')"
                >
                    <RotateCcw class="size-4" /> Retry final reconciliation
                </Button>
                <Button
                    v-if="
                        activeItem.batchItem.actions.pause &&
                        ['uploading', 'retrying', 'offline'].includes(
                            connectionState,
                        )
                    "
                    type="button"
                    variant="outline"
                    @click="$emit('pause')"
                >
                    <CirclePause class="size-4" /> Pause
                </Button>
                <Button
                    v-if="
                        activeItem.batchItem.actions.authorize &&
                        ['paused', 'error', 'offline'].includes(
                            connectionState,
                        ) &&
                        !['failed', 'expired', 'processing'].includes(
                            activeItem.status,
                        )
                    "
                    type="button"
                    @click="$emit('retryTransfer')"
                >
                    <RotateCcw class="size-4" />
                    {{
                        activeItem.status === 'paused'
                            ? 'Resume batch'
                            : 'Retry transfer'
                    }}
                </Button>
                <Button
                    v-if="
                        activeItem.status === 'failed' &&
                        activeItem.batchItem.actions.retry
                    "
                    type="button"
                    @click="$emit('retryValidation')"
                >
                    <RotateCcw class="size-4" /> Retry validation
                </Button>
                <Button
                    v-if="activeItem.batchItem.actions.cancel"
                    type="button"
                    variant="destructive"
                    @click="skipDialogOpen = true"
                >
                    <SkipForward class="size-4" /> Skip this episode and
                    continue
                </Button>
            </div>
        </div>

        <ol
            class="divide-y overflow-hidden rounded-2xl border"
            aria-label="Episode upload queue"
        >
            <li
                v-for="item in items"
                :key="item.batchItem.upload_uuid"
                class="flex items-center gap-3 p-3"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full border"
                >
                    <Check
                        v-if="item.status === 'completed'"
                        class="size-4 text-emerald-600"
                    />
                    <SkipForward
                        v-else-if="item.status === 'cancelled'"
                        class="size-4 text-muted-foreground"
                    />
                    <LoaderCircle
                        v-else-if="item === activeItem"
                        class="size-4 text-primary motion-safe:animate-spin"
                    />
                    <AlertTriangle
                        v-else-if="
                            item.status === 'failed' ||
                            item.status === 'expired'
                        "
                        class="size-4 text-destructive"
                    />
                    <span v-else class="text-xs">{{
                        item.batchItem.position
                    }}</span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium"
                        >{{ item.batchItem.episode.identity }} ·
                        {{ item.batchItem.episode.title }}</span
                    >
                    <span
                        class="block truncate text-xs text-muted-foreground"
                        >{{
                            item.file?.name ?? item.batchItem.source_basename
                        }}</span
                    >
                </span>
                <Badge variant="outline">{{ statusLabel(item) }}</Badge>
            </li>
        </ol>

        <Dialog v-model:open="skipDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Skip this episode?</DialogTitle>
                    <DialogDescription>
                        The staged upload bytes for
                        {{ activeItem?.batchItem.episode.identity }} will be
                        discarded where safe. This cannot be undone, and the
                        queue will continue with the next episode.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="skipDialogOpen = false"
                    >
                        Keep episode
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        @click="confirmSkip"
                    >
                        Skip and continue
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
