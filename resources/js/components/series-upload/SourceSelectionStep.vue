<script setup lang="ts">
import {
    FileVideo2,
    Files,
    FolderOpen,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { supportedEpisodeAccept } from '@/composables/useSeriesUploadWizard';
import type { SeriesSourceIssue } from '@/composables/useSeriesUploadWizard';

defineProps<{
    totalSelected: number;
    blockingIssues: SeriesSourceIssue[];
    excludedIssues: SeriesSourceIssue[];
}>();

const emit = defineEmits<{
    select: [files: File[]];
}>();

const folderInput = ref<HTMLInputElement | null>(null);
const filesInput = ref<HTMLInputElement | null>(null);

function selectFiles(event: Event): void {
    const input = event.target as HTMLInputElement;

    emit('select', Array.from(input.files ?? []));
    input.value = '';
}
</script>

<template>
    <section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
        <div class="flex flex-col gap-2">
            <p class="text-xs font-medium text-primary">Step 1 of 6</p>
            <h2
                id="wizard-step-1"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight outline-none"
            >
                Select episodes
            </h2>
            <p class="max-w-2xl text-sm leading-6 text-muted-foreground">
                Choose one episode, a season, or a complete show folder. The
                files are checked locally before you continue.
            </p>
        </div>

        <div
            class="flex min-h-64 flex-col items-center justify-center gap-5 rounded-2xl border-2 border-dashed bg-muted/20 p-6 text-center sm:p-8"
        >
            <span
                class="flex size-16 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <FileVideo2 class="size-8" />
            </span>
            <div class="flex flex-col gap-1">
                <p class="font-medium">Choose your episode source</p>
                <p
                    id="supported-episode-formats"
                    class="text-sm text-muted-foreground"
                >
                    MKV, MP4, M4V, AVI, MOV, TS, M2TS, or WebM
                </p>
            </div>
            <div
                class="flex flex-col items-center justify-center gap-2 sm:flex-row"
            >
                <Button type="button" @click="folderInput?.click()">
                    <FolderOpen class="size-4" />
                    Choose folder
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="filesInput?.click()"
                >
                    <Files class="size-4" />
                    Choose episode files
                </Button>
            </div>
            <p class="max-w-xl text-xs leading-5 text-muted-foreground">
                Folder selection may include an episode, season, or complete
                show directory. Non-video files and known extras are left out
                automatically.
            </p>
        </div>

        <input
            ref="folderInput"
            type="file"
            :accept="supportedEpisodeAccept"
            multiple
            webkitdirectory
            directory
            class="sr-only"
            aria-label="Choose an episode, season, or complete show folder"
            aria-describedby="supported-episode-formats"
            @change="selectFiles($event)"
        />
        <input
            ref="filesInput"
            type="file"
            :accept="supportedEpisodeAccept"
            multiple
            class="sr-only"
            aria-label="Choose individual episode video files"
            aria-describedby="supported-episode-formats"
            @change="selectFiles($event)"
        />

        <template v-if="totalSelected > 0">
            <section
                v-if="blockingIssues.length"
                class="flex flex-col gap-3 rounded-2xl border border-destructive/40 bg-destructive/5 p-4"
                aria-labelledby="blocking-issues-title"
                role="alert"
            >
                <div class="flex items-start gap-3">
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-destructive"
                    />
                    <div>
                        <h3 id="blocking-issues-title" class="font-semibold">
                            Fix before continuing
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Choose another folder or file set after resolving
                            these issues.
                        </p>
                    </div>
                </div>
                <ul class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="issue in blockingIssues"
                        :key="issue.id"
                        class="rounded-lg bg-background/70 px-3 py-2"
                    >
                        <span v-if="issue.filename" class="font-medium"
                            >{{ issue.filename }}: </span
                        >{{ issue.message }}
                    </li>
                </ul>
            </section>

            <details
                v-if="excludedIssues.length"
                class="rounded-2xl border bg-muted/15 p-4"
            >
                <summary class="cursor-pointer text-sm font-medium">
                    {{ excludedIssues.length }}
                    {{ excludedIssues.length === 1 ? 'file' : 'files' }} not
                    included
                </summary>
                <ul class="mt-3 flex flex-col gap-2 text-sm">
                    <li
                        v-for="issue in excludedIssues"
                        :key="issue.id"
                        class="rounded-lg bg-background px-3 py-2"
                    >
                        <span v-if="issue.filename" class="font-medium"
                            >{{ issue.filename }}: </span
                        ><span class="text-muted-foreground">{{
                            issue.message
                        }}</span>
                    </li>
                </ul>
            </details>
        </template>

        <div class="flex items-start gap-3 rounded-xl border bg-card p-4">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-emerald-600" />
            <p class="text-sm leading-6 text-muted-foreground">
                Nothing is hashed or uploaded in this step. Selected files stay
                only in this page and are cleared when you leave or refresh.
            </p>
        </div>
    </section>
</template>
