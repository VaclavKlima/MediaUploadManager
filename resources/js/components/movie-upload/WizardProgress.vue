<script setup lang="ts">
import {
    Check,
    Circle,
    CloudUpload,
    Film,
    FolderSearch2,
    HardDrive,
    PartyPopper,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { UploadWizardStep } from '@/types/movie-upload';

const props = defineProps<{
    currentStep: UploadWizardStep;
    hasSource: boolean;
    hasConfirmedMovie: boolean;
    hasReservation: boolean;
}>();

interface WizardRoadmapStep {
    number: UploadWizardStep;
    title: string;
    description: string;
    icon: LucideIcon;
    locked: boolean;
}

const steps = computed<WizardRoadmapStep[]>(() => [
    {
        number: 1,
        title: 'Select file',
        description: 'Choose a local video',
        icon: FolderSearch2,
        locked: false,
    },
    {
        number: 2,
        title: 'Choose movie',
        description: 'Confirm the TMDB match',
        icon: Film,
        locked: !props.hasSource && props.currentStep < 2,
    },
    {
        number: 3,
        title: 'Choose storage',
        description: 'Pick an eligible disk',
        icon: HardDrive,
        locked: !props.hasConfirmedMovie && props.currentStep < 3,
    },
    {
        number: 4,
        title: 'Upload and validate',
        description: 'Transfer and server checks',
        icon: CloudUpload,
        locked: !props.hasReservation,
    },
    {
        number: 5,
        title: 'Complete',
        description: 'Ready in the library',
        icon: PartyPopper,
        locked: props.currentStep < 5,
    },
]);

function isComplete(step: UploadWizardStep): boolean {
    return props.currentStep > step;
}
</script>

<template>
    <aside
        class="hidden w-56 shrink-0 border-r bg-muted/15 p-4 lg:block"
        aria-label="Movie upload progress"
    >
        <ol class="flex h-full flex-col gap-1">
            <li v-for="step in steps" :key="step.number">
                <div
                    class="flex gap-3 rounded-lg px-2.5 py-2.5"
                    :class="{
                        'bg-primary/7': step.number === currentStep,
                        'opacity-45': step.locked,
                    }"
                    :aria-current="
                        step.number === currentStep ? 'step' : undefined
                    "
                    :aria-disabled="step.locked || undefined"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full border bg-background"
                        :class="{
                            'border-primary bg-primary text-primary-foreground':
                                step.number === currentStep,
                            'border-emerald-500 bg-emerald-500 text-white':
                                isComplete(step.number),
                        }"
                    >
                        <Check
                            v-if="isComplete(step.number)"
                            class="size-3.5"
                        />
                        <component
                            :is="step.icon"
                            v-else-if="
                                step.locked || step.number === currentStep
                            "
                            class="size-3.5"
                        />
                        <Circle v-else class="size-2.5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">{{
                            step.title
                        }}</span>
                        <span
                            class="mt-0.5 block text-[11px] leading-4 text-muted-foreground"
                            >{{ step.description }}</span
                        >
                    </span>
                </div>
            </li>
        </ol>
    </aside>
</template>
