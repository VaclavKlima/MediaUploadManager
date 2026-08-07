<script setup lang="ts">
import {
    Clock3,
    FileVideo2,
    FolderOpen,
    LoaderCircle,
    RotateCcw,
    ShieldCheck,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { UploadSession } from '@/types/movie-upload';

defineProps<{
    filename: string;
    resumableSessions: UploadSession[];
    isLoadingSessions: boolean;
    recoveryError: string;
}>();

const emit = defineEmits<{
    select: [file: File];
    recover: [session: UploadSession, file: File];
    open: [session: UploadSession];
}>();

const input = ref<HTMLInputElement | null>(null);
const recoveryInput = ref<HTMLInputElement | null>(null);
const recoverySession = ref<UploadSession | null>(null);

function selectFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    if (file) {
        emit('select', file);
    }

    target.value = '';
}

function openChooser(): void {
    input.value?.click();
}

function chooseRecoveryFile(session: UploadSession): void {
    if (session.status === 'processing' || session.status === 'failed') {
        emit('open', session);

        return;
    }

    recoverySession.value = session;
    recoveryInput.value?.click();
}

function recoverFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    if (file && recoverySession.value) {
        emit('recover', recoverySession.value, file);
    }

    target.value = '';
    recoverySession.value = null;
}

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Unavailable';
}
</script>

<template>
    <section class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-primary">Step 1 of 5</p>
            <h2
                id="wizard-step-1"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Choose the source file
            </h2>
            <p class="max-w-2xl text-sm leading-6 text-muted-foreground">
                Start with the movie on this device. Its filename helps identify
                the title and build the final Jellyfin path.
            </p>
        </div>

        <div
            v-if="
                isLoadingSessions || resumableSessions.length || recoveryError
            "
            class="flex flex-col gap-3 rounded-2xl border bg-muted/20 p-4"
            aria-label="Resumable upload sessions"
        >
            <div>
                <h3 class="font-semibold">Continue an upload</h3>
                <p class="text-sm text-muted-foreground">
                    Active transfers require the exact local file. Validation
                    and failed sessions reopen directly without reselection.
                </p>
            </div>

            <div
                v-if="isLoadingSessions"
                class="flex items-center gap-2 text-sm text-muted-foreground"
                role="status"
            >
                <LoaderCircle class="size-4 motion-safe:animate-spin" />
                Finding active sessions…
            </div>
            <p
                v-else-if="recoveryError"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ recoveryError }}
            </p>
            <article
                v-for="session in resumableSessions"
                :key="session.uuid"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">
                        {{ session.original_filename }}
                    </p>
                    <p class="truncate font-mono text-xs text-muted-foreground">
                        {{ session.target_relative_path }}
                    </p>
                    <p
                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Clock3 class="size-3" />
                        {{ session.status }} · expires
                        {{ formatDate(session.expires_at) }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    @click="chooseRecoveryFile(session)"
                >
                    <RotateCcw class="size-4" />
                    {{
                        session.status === 'processing'
                            ? 'Open validation'
                            : session.status === 'failed'
                              ? 'Review failure'
                              : 'Reselect & resume'
                    }}
                </Button>
            </article>
        </div>

        <div
            role="button"
            tabindex="0"
            class="group flex min-h-64 cursor-pointer flex-col items-center justify-center gap-4 rounded-2xl border-2 border-dashed bg-muted/20 p-8 text-center transition-colors hover:border-primary/50 hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
            @click="openChooser"
            @keydown.enter.prevent="openChooser"
            @keydown.space.prevent="openChooser"
        >
            <span
                class="flex size-16 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <FileVideo2 class="size-8" />
            </span>
            <div>
                <p class="font-medium">
                    {{ filename || 'Select a movie file' }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    MKV, MP4, M4V, AVI, MOV, TS, M2TS, or WebM
                </p>
            </div>
            <span
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
            >
                <FolderOpen class="size-4" />
                {{ filename ? 'Choose a different file' : 'Browse files' }}
            </span>
        </div>

        <input
            ref="input"
            type="file"
            accept=".mkv,.mp4,.m4v,.avi,.mov,.ts,.m2ts,.webm"
            class="sr-only"
            aria-label="Select movie source file"
            @change="selectFile"
        />
        <input
            ref="recoveryInput"
            type="file"
            accept=".mkv,.mp4,.m4v,.avi,.mov,.ts,.m2ts,.webm"
            class="sr-only"
            aria-label="Reselect file for resumable upload"
            @change="recoverFile"
        />

        <div class="flex items-start gap-3 rounded-xl border bg-card p-4">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-emerald-600" />
            <p class="text-sm leading-6 text-muted-foreground">
                The file stays in browser memory. These preparation steps send
                only its basename—no movie bytes are uploaded yet. Leaving or
                refreshing this page resets the draft.
            </p>
        </div>
    </section>
</template>
