<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    Clock3,
    CloudUpload,
    Gauge,
    HardDrive,
    LoaderCircle,
    Radio,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type {
    UploadConnectionState,
    UploadSession,
} from '@/types/movie-upload';

const props = defineProps<{
    session: UploadSession;
    connectionState: UploadConnectionState;
    transferredBytes: number;
    speedBytesPerSecond: number;
    etaSeconds: number | null;
    errorMessage: string;
}>();

const percentage = computed(() =>
    props.session.declared_bytes > 0
        ? Math.min(
              100,
              (props.transferredBytes / props.session.declared_bytes) * 100,
          )
        : 0,
);

const byteFormatter = new Intl.NumberFormat(undefined, {
    style: 'unit',
    unit: 'byte',
    notation: 'compact',
    maximumFractionDigits: 1,
});

function formatBytes(bytes: number): string {
    return byteFormatter.format(bytes);
}

function formatSpeed(bytesPerSecond: number): string {
    return bytesPerSecond > 0
        ? `${formatBytes(bytesPerSecond)}/s`
        : 'Calculating…';
}

function formatEta(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds)) {
        return 'Calculating…';
    }

    if (seconds < 60) {
        return `${Math.max(1, Math.ceil(seconds))} sec`;
    }

    const minutes = Math.ceil(seconds / 60);

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} hr ${minutes % 60} min`;
}

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Unavailable';
}

function formatDuration(milliseconds: number): string {
    const totalSeconds = Math.round(milliseconds / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    return [hours, minutes, seconds]
        .map((part) => part.toString().padStart(2, '0'))
        .join(':');
}

const stateLabel = computed(() => {
    const labels: Record<UploadConnectionState, string> = {
        ready: 'Ready to start',
        authorizing: 'Refreshing authorization',
        uploading: 'Uploading',
        retrying: 'Connection interrupted—retrying',
        offline: 'Offline—waiting for connection',
        paused: 'Paused',
        error: 'Needs attention',
        received: 'Upload received',
        cancelled: 'Cancelled',
    };

    return labels[props.connectionState];
});
</script>

<template>
    <section class="flex min-h-full flex-col gap-5">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-primary">Step 5 of 5</p>
            <h2
                id="wizard-step-5"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Upload movie bytes
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Transfer sequential protected chunks directly to the selected
                disk. Validation and final placement begin after receipt.
            </p>
        </div>

        <div
            v-if="session.status === 'completed'"
            class="flex items-start gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-emerald-900 dark:text-emerald-100"
            role="status"
        >
            <CheckCircle2 class="mt-0.5 size-6 shrink-0" />
            <div>
                <h3 class="font-semibold">Movie ready in Jellyfin storage</h3>
                <p class="mt-1 text-sm opacity-90">
                    Validation and exclusive final placement completed without
                    copying or overwriting media bytes.
                </p>
            </div>
        </div>

        <div
            v-else-if="
                session.status === 'processing' ||
                connectionState === 'received'
            "
            class="flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/10 p-4"
            role="status"
        >
            <LoaderCircle
                class="mt-0.5 size-6 shrink-0 text-primary motion-safe:animate-spin"
            />
            <div>
                <h3 class="font-semibold">Validating media</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    The staged bytes are safe while container, duration, video
                    streams, disk identity, and final path are verified.
                </p>
            </div>
        </div>

        <div
            v-else-if="session.status === 'failed'"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-destructive"
            role="alert"
        >
            <XCircle class="mt-0.5 size-6 shrink-0" />
            <div>
                <h3 class="font-semibold">Validation needs attention</h3>
                <p class="mt-1 text-sm">
                    {{
                        session.failure?.detail ||
                        'Processing failed safely. The file was retained.'
                    }}
                </p>
                <p
                    v-if="session.failure?.code"
                    class="mt-2 font-mono text-xs opacity-80"
                >
                    {{ session.failure.code }}
                </p>
            </div>
        </div>

        <div
            v-else-if="errorMessage"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-destructive"
            role="alert"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <p class="text-sm">{{ errorMessage }}</p>
        </div>

        <div
            v-if="session.replacement"
            class="flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-amber-950 dark:text-amber-100"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0 text-sm leading-6">
                <h3 class="font-semibold">
                    {{
                        session.status === 'completed'
                            ? 'Current primary replaced without a backup'
                            : 'Confirmed current-primary replacement'
                    }}
                </h3>
                <p class="mt-1">
                    {{
                        session.replacement.disk.label ||
                        session.replacement.disk.id
                    }}
                    ·
                    <span class="font-mono break-all">
                        {{ session.replacement.relative_path }}
                    </span>
                    · {{ formatBytes(session.replacement.size_bytes) }}
                </p>
                <p class="mt-1 font-medium">
                    {{
                        session.replacement.method === 'atomic_same_path_swap'
                            ? 'Atomic same-path swap after successful validation.'
                            : 'New inode finalized first; exact old file deleted afterward.'
                    }}
                </p>
            </div>
        </div>

        <div class="rounded-2xl border bg-muted/20 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <CloudUpload class="size-6" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">
                            {{ session.original_filename }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatBytes(transferredBytes) }} of
                            {{ formatBytes(session.declared_bytes) }}
                        </p>
                    </div>
                </div>
                <Badge variant="secondary">
                    <Radio class="size-3" />
                    {{ stateLabel }}
                </Badge>
            </div>

            <div class="mt-5 flex flex-col gap-2">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-medium"
                        >{{ percentage.toFixed(1) }}%</span
                    >
                    <span class="text-muted-foreground">
                        {{
                            formatBytes(
                                Math.max(
                                    session.declared_bytes - transferredBytes,
                                    0,
                                ),
                            )
                        }}
                        remaining
                    </span>
                </div>
                <div
                    class="h-3 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-label="Movie upload progress"
                    :aria-valuenow="Math.round(percentage)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-[width] duration-300 motion-reduce:transition-none"
                        :style="{ width: `${percentage}%` }"
                    />
                </div>
            </div>

            <dl class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-3">
                    <dt
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Gauge class="size-4" /> Rolling speed
                    </dt>
                    <dd class="mt-1 font-medium">
                        {{ formatSpeed(speedBytesPerSecond) }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <dt
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Clock3 class="size-4" /> Estimated time
                    </dt>
                    <dd class="mt-1 font-medium">
                        {{ formatEta(etaSeconds) }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <dt
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <HardDrive class="size-4" /> Destination disk
                    </dt>
                    <dd class="mt-1 truncate font-medium">
                        {{ session.disk.label || session.disk.id }}
                    </dd>
                </div>
            </dl>
        </div>

        <dl class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border p-4">
                <dt class="text-xs font-medium text-muted-foreground">
                    Protected staging destination
                </dt>
                <dd class="mt-2 font-mono text-sm break-all">
                    {{ session.disk.id }}:{{ session.staging_relative_path }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs font-medium text-muted-foreground">
                    Inactivity expiry
                </dt>
                <dd class="mt-2 text-sm font-medium">
                    {{ formatDate(session.expires_at) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4 sm:col-span-2">
                <dt class="text-xs font-medium text-muted-foreground">
                    Final Jellyfin target after validation
                </dt>
                <dd class="mt-2 font-mono text-sm break-all">
                    {{ session.target_relative_path }}
                </dd>
            </div>
        </dl>

        <dl
            v-if="session.finalized"
            class="grid gap-3 rounded-2xl border bg-emerald-500/5 p-4 sm:grid-cols-2 lg:grid-cols-3"
            aria-label="Finalized media technical metadata"
        >
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs font-medium text-muted-foreground">
                    Final disk and path
                </dt>
                <dd class="mt-1 font-mono text-sm break-all">
                    {{
                        session.finalized.disk.label ||
                        session.finalized.disk.id
                    }}
                    · {{ session.finalized.relative_path }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-muted-foreground">
                    Container
                </dt>
                <dd class="mt-1 font-medium">
                    {{ session.finalized.container }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-muted-foreground">
                    Duration
                </dt>
                <dd class="mt-1 font-medium">
                    {{
                        formatDuration(session.finalized.duration_milliseconds)
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-muted-foreground">
                    File size
                </dt>
                <dd class="mt-1 font-medium">
                    {{ formatBytes(session.finalized.size_bytes) }}
                </dd>
            </div>
            <div
                v-for="video in session.finalized.video"
                :key="`v-${video.index}`"
            >
                <dt class="text-xs font-medium text-muted-foreground">
                    Video {{ video.index + 1 }}
                </dt>
                <dd class="mt-1 font-medium">
                    {{ video.width }}×{{ video.height }} · {{ video.codec }}
                </dd>
            </div>
            <div v-if="session.finalized.audio.length" class="sm:col-span-2">
                <dt class="text-xs font-medium text-muted-foreground">Audio</dt>
                <dd class="mt-1 font-medium">
                    {{
                        session.finalized.audio
                            .map(
                                (audio) =>
                                    `${audio.codec}${audio.channels ? ` ${audio.channels}ch` : ''}${audio.language ? ` (${audio.language})` : ''}`,
                            )
                            .join(' · ')
                    }}
                </dd>
            </div>
        </dl>
    </section>
</template>
