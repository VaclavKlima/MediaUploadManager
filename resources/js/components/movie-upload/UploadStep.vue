<script setup lang="ts">
import {
    AlertTriangle,
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
import UploadCompletionNotificationControl from '@/components/UploadCompletionNotificationControl.vue';
import type { UploadCompletionNotificationState } from '@/composables/useUploadCompletionNotifications';
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
    notificationState: UploadCompletionNotificationState;
    notificationError: string;
}>();

defineEmits<{
    enableNotifications: [];
    disableNotifications: [];
    testNotifications: [];
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

const stateLabel = computed(() => {
    const labels: Record<UploadConnectionState, string> = {
        ready: 'Ready to resume',
        authorizing: 'Authorizing',
        uploading: 'Uploading',
        retrying: 'Retrying',
        offline: 'Waiting for connection',
        paused: 'Paused',
        error: 'Needs attention',
        received: 'Validating',
        cancelled: 'Cancelled',
    };

    return props.session.status === 'processing'
        ? 'Validating'
        : labels[props.connectionState];
});
</script>

<template>
    <section class="mx-auto flex min-h-full w-full max-w-4xl flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <p class="text-xs font-medium text-primary">Step 4 of 5</p>
            <h2
                id="wizard-step-4"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Upload and validate
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Keep this page open while the transfer and server validation
                finish.
            </p>
        </div>

        <UploadCompletionNotificationControl
            :state="notificationState"
            :error-message="notificationError"
            @enable="$emit('enableNotifications')"
            @disable="$emit('disableNotifications')"
            @test="$emit('testNotifications')"
        />

        <div
            v-if="
                session.status === 'processing' ||
                connectionState === 'received'
            "
            class="flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/10 p-4"
            role="status"
        >
            <LoaderCircle
                class="mt-0.5 size-5 shrink-0 text-primary motion-safe:animate-spin"
            />
            <div>
                <h3 class="font-semibold">Validating media</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Transfer is complete. The server is checking the file before
                    final placement.
                </p>
            </div>
        </div>

        <div
            v-else-if="session.status === 'failed'"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-destructive"
            role="alert"
        >
            <XCircle class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0">
                <h3 class="font-semibold">Validation needs attention</h3>
                <p class="mt-1 text-sm">
                    {{
                        session.failure?.detail ||
                        'Processing failed safely. The uploaded file was retained.'
                    }}
                </p>
                <p
                    v-if="errorMessage"
                    class="mt-3 rounded-lg border border-destructive/30 bg-background/80 p-3 text-sm text-foreground"
                >
                    {{ errorMessage }}
                </p>
            </div>
        </div>

        <div
            v-else-if="session.status === 'cancelled'"
            class="flex items-start gap-3 rounded-xl border bg-muted/20 p-4"
            role="status"
        >
            <XCircle class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <div>
                <h3 class="font-semibold">Upload cancelled</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    The partial upload and its reservation were removed.
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
                <Badge variant="secondary"
                    ><Radio class="size-3" /> {{ stateLabel }}</Badge
                >
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
                        <Gauge class="size-4" /> Speed
                    </dt>
                    <dd class="mt-1 font-medium">
                        {{ formatSpeed(speedBytesPerSecond) }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <dt
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Clock3 class="size-4" /> Time remaining
                    </dt>
                    <dd class="mt-1 font-medium">
                        {{ formatEta(etaSeconds) }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <dt
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <HardDrive class="size-4" /> Storage
                    </dt>
                    <dd class="mt-1 truncate font-medium">
                        {{ session.disk.label || session.disk.id }}
                    </dd>
                </div>
            </dl>
        </div>
    </section>
</template>
