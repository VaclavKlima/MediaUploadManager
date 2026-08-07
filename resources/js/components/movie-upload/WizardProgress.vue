<script setup lang="ts">
import {
    Check,
    Circle,
    CloudUpload,
    Film,
    FolderSearch2,
    HardDrive,
    LockKeyhole,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { UploadWizardStep } from '@/types/movie-upload';

const props = defineProps<{
    currentStep: UploadWizardStep;
    hasSource: boolean;
    hasConfirmedMovie: boolean;
    canEnterCapacity: boolean;
    hasReservation: boolean;
}>();

interface WizardRoadmapStep {
    number: 1 | 2 | 3 | 4 | 5;
    title: string;
    description: string;
    icon: LucideIcon;
    locked: boolean;
}

const steps = computed<WizardRoadmapStep[]>(() => [
    {
        number: 1,
        title: 'Source file',
        description: 'Choose the local video',
        icon: FolderSearch2,
        locked: false,
    },
    {
        number: 2,
        title: 'Identify movie',
        description: 'Confirm its TMDB identity',
        icon: Film,
        locked: false,
    },
    {
        number: 3,
        title: 'Check destination',
        description: 'Preview path and conflicts',
        icon: HardDrive,
        locked: false,
    },
    {
        number: 4,
        title: 'Reserve capacity',
        description: props.hasReservation
            ? 'Pending reservation created'
            : 'Choose eligible storage',
        icon: LockKeyhole,
        locked: !props.canEnterCapacity,
    },
    {
        number: 5,
        title: 'Upload',
        description: props.hasReservation
            ? 'Protected resumable transfer'
            : 'Reserve capacity first',
        icon: CloudUpload,
        locked: !props.hasReservation,
    },
]);

function isComplete(step: number): boolean {
    if (step === 1) {
        return props.hasSource && props.currentStep > 1;
    }

    if (step === 2) {
        return props.hasConfirmedMovie && props.currentStep > 2;
    }

    if (step === 3) {
        return props.currentStep > 3;
    }

    if (step === 4) {
        return props.hasReservation && props.currentStep > 4;
    }

    return false;
}
</script>

<template>
    <aside
        class="hidden w-64 shrink-0 border-r bg-muted/20 p-5 lg:block"
        aria-label="Movie upload progress"
    >
        <ol class="flex h-full flex-col gap-2">
            <li v-for="step in steps" :key="step.number">
                <div
                    class="flex gap-3 rounded-xl border border-transparent p-3"
                    :class="{
                        'border-primary/20 bg-primary/5':
                            step.number === currentStep,
                        'opacity-55': step.locked,
                    }"
                    :aria-current="
                        step.number === currentStep ? 'step' : undefined
                    "
                    :aria-disabled="step.locked || undefined"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full border bg-background"
                        :class="{
                            'border-primary bg-primary text-primary-foreground':
                                step.number === currentStep,
                            'border-emerald-500 bg-emerald-500 text-white':
                                isComplete(step.number),
                        }"
                    >
                        <Check v-if="isComplete(step.number)" class="size-4" />
                        <component
                            :is="step.icon"
                            v-else-if="
                                step.locked || step.number === currentStep
                            "
                            class="size-4"
                        />
                        <Circle v-else class="size-3" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">
                            {{ step.number }}. {{ step.title }}
                        </span>
                        <span
                            class="mt-0.5 block text-xs leading-5 text-muted-foreground"
                        >
                            {{ step.description }}
                        </span>
                    </span>
                </div>
            </li>
        </ol>
    </aside>
</template>
