<script setup lang="ts">
import {
    Check,
    Circle,
    CloudUpload,
    FolderSearch2,
    HardDrive,
    ListChecks,
    LockKeyhole,
    PartyPopper,
    Tv2,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { SeriesUploadWizardStep } from '@/composables/useSeriesUploadWizard';

const props = defineProps<{
    currentStep: SeriesUploadWizardStep;
    hasValidSource: boolean;
    hasConfirmedShow: boolean;
    hasConfirmedReview: boolean;
    hasAdmittedBatch: boolean;
    hasCompletedBatch: boolean;
}>();

interface WizardRoadmapStep {
    number: SeriesUploadWizardStep;
    title: string;
    description: string;
    icon: LucideIcon;
    locked: boolean;
}

const steps = computed<WizardRoadmapStep[]>(() => [
    {
        number: 1,
        title: 'Select episodes',
        description: 'Choose local episode videos',
        icon: FolderSearch2,
        locked: false,
    },
    {
        number: 2,
        title: 'Choose show',
        description: 'Confirm the TMDB show',
        icon: Tv2,
        locked: !props.hasValidSource && props.currentStep < 2,
    },
    {
        number: 3,
        title: 'Review episodes',
        description: 'Check every episode match',
        icon: ListChecks,
        locked: !props.hasConfirmedShow,
    },
    {
        number: 4,
        title: 'Choose storage',
        description: 'Pick an eligible disk',
        icon: HardDrive,
        locked: !props.hasConfirmedReview,
    },
    {
        number: 5,
        title: 'Upload and validate',
        description: 'Transfer and server checks',
        icon: CloudUpload,
        locked: !props.hasAdmittedBatch,
    },
    {
        number: 6,
        title: 'Complete',
        description: 'Episodes are in the library',
        icon: PartyPopper,
        locked: !props.hasCompletedBatch,
    },
]);

function isComplete(step: SeriesUploadWizardStep): boolean {
    return props.currentStep > step;
}
</script>

<template>
    <aside
        class="hidden w-56 shrink-0 border-r bg-muted/15 p-4 lg:block"
        aria-label="Show upload progress"
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
                        <LockKeyhole v-else-if="step.locked" class="size-3.5" />
                        <component
                            :is="step.icon"
                            v-else-if="step.number === currentStep"
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
