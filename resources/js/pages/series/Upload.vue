<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    FileUp,
    FilePenLine,
    ListChecks,
    RotateCcw,
    Tv2,
} from '@lucide/vue';
import { nextTick, watch } from 'vue';
import CompletionStep from '@/components/series-upload/CompletionStep.vue';
import IdentifyShowStep from '@/components/series-upload/IdentifyShowStep.vue';
import ReviewEpisodesStep from '@/components/series-upload/ReviewEpisodesStep.vue';
import SourceSelectionStep from '@/components/series-upload/SourceSelectionStep.vue';
import StorageStep from '@/components/series-upload/StorageStep.vue';
import UploadStep from '@/components/series-upload/UploadStep.vue';
import WizardProgress from '@/components/series-upload/WizardProgress.vue';
import { Button } from '@/components/ui/button';
import UploadResultNotificationControl from '@/components/UploadResultNotificationControl.vue';
import {
    supportedEpisodeAccept,
    useSeriesUploadWizard,
} from '@/composables/useSeriesUploadWizard';
import { useUploadResultNotifications } from '@/composables/useUploadResultNotifications';
import { dashboard } from '@/routes';
import { upload as seriesUpload } from '@/routes/series';
import type { SeriesBatch } from '@/types/series';

defineOptions({
    layout: {
        contentClass: 'h-[100svh] overflow-hidden md:h-[calc(100svh-1rem)]',
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Upload show episodes',
                href: seriesUpload(),
            },
        ],
    },
});

const props = defineProps<{ fingerprintWindowBytes: number }>();

const wizard = useSeriesUploadWizard(props.fingerprintWindowBytes);
const notifications = useUploadResultNotifications('series');

watch(wizard.currentStep, async (step) => {
    await nextTick();
    document
        .getElementById(`wizard-step-${step}`)
        ?.focus({ preventScroll: true });
});

watch(
    () =>
        [
            wizard.currentStep.value,
            wizard.activeQueueItem.value?.status,
        ] as const,
    ([step, status]) => {
        const batch = wizard.admittedBatch.value;

        if (!batch) {
            return;
        }

        if (step === 6) {
            notifications.notifyUploadResult({
                uploadUuid: batch.uuid,
                result: 'completed',
                body: `${batch.series.name}: ${wizard.completedCount.value} uploaded, ${wizard.skippedCount.value} skipped.`,
            });
        } else if (status === 'failed' || status === 'expired') {
            notifications.notifyUploadResult({
                uploadUuid: batch.uuid,
                result: 'failed',
                body: `${batch.series.name} stopped at ${wizard.activeQueueItem.value?.batchItem.episode.identity ?? 'an episode'}.`,
            });
        }
    },
);

function selectRecoveryFiles(event: Event, batch: SeriesBatch): void {
    const input = event.target as HTMLInputElement;
    void wizard.recoverBatch(batch, Array.from(input.files ?? []));
    input.value = '';
}
</script>

<template>
    <div
        class="flex h-0 min-h-0 flex-1 flex-col overflow-hidden p-3 md:p-4 lg:p-6"
    >
        <Head title="Upload show episodes" />

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{ wizard.statusMessage.value }}
        </p>

        <section
            class="flex min-h-0 flex-1 overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
            aria-label="Upload show episodes wizard"
        >
            <WizardProgress
                :current-step="wizard.currentStep.value"
                :has-valid-source="wizard.canContinue.value"
                :has-confirmed-show="wizard.confirmedSeries.value !== null"
                :has-confirmed-review="wizard.reviewConfirmed.value"
                :has-admitted-batch="wizard.admittedBatch.value !== null"
                :has-completed-batch="wizard.currentStep.value === 6"
            />

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="shrink-0 border-b px-4 py-3 sm:px-6">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Tv2 class="size-4" />
                        </span>
                        <h1 class="truncate text-lg font-semibold">
                            Upload show episodes
                        </h1>
                        <span
                            class="ml-auto shrink-0 text-xs font-medium text-muted-foreground lg:hidden"
                        >
                            Step {{ wizard.currentStep.value }} of 6
                        </span>
                    </div>
                </header>

                <div
                    data-testid="wizard-content-pane"
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4 sm:p-6"
                >
                    <div
                        v-if="wizard.currentStep.value === 1"
                        class="flex flex-col gap-6"
                    >
                        <section
                            v-if="wizard.resumableBatches.value.length"
                            class="rounded-2xl border border-primary/20 bg-primary/5 p-4"
                            aria-labelledby="resume-show-upload-heading"
                        >
                            <div class="flex items-start gap-3">
                                <FileUp class="mt-0.5 size-5 text-primary" />
                                <div class="min-w-0 flex-1">
                                    <h2
                                        id="resume-show-upload-heading"
                                        class="font-semibold"
                                    >
                                        Resume an unfinished Show upload
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Reselect all files that still need
                                        transfer. Files are verified locally and
                                        are never saved in the browser.
                                    </p>
                                    <div class="mt-3 flex flex-col gap-2">
                                        <div
                                            v-for="batch in wizard
                                                .resumableBatches.value"
                                            :key="batch.uuid"
                                            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background p-3"
                                        >
                                            <span>
                                                <span
                                                    class="block text-sm font-medium"
                                                    >{{
                                                        batch.series.name
                                                    }}</span
                                                >
                                                <span
                                                    class="block text-xs text-muted-foreground"
                                                    >{{
                                                        batch.items.filter(
                                                            (item) =>
                                                                [
                                                                    'pending',
                                                                    'uploading',
                                                                    'paused',
                                                                ].includes(
                                                                    item.status,
                                                                ),
                                                        ).length
                                                    }}
                                                    files needed</span
                                                >
                                            </span>
                                            <button
                                                v-if="
                                                    batch.items.filter((item) =>
                                                        [
                                                            'pending',
                                                            'uploading',
                                                            'paused',
                                                        ].includes(item.status),
                                                    ).length === 0
                                                "
                                                type="button"
                                                class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground"
                                                :disabled="
                                                    wizard.isRecovering.value
                                                "
                                                @click.prevent="
                                                    wizard.recoverBatch(
                                                        batch,
                                                        [],
                                                    )
                                                "
                                            >
                                                Open batch
                                            </button>
                                            <div
                                                v-else-if="
                                                    batch.items.some((item) =>
                                                        [
                                                            'pending',
                                                            'uploading',
                                                            'paused',
                                                        ].includes(item.status),
                                                    )
                                                "
                                                class="flex flex-wrap gap-2"
                                            >
                                                <label
                                                    class="cursor-pointer rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground"
                                                >
                                                    Select remaining files
                                                    <input
                                                        type="file"
                                                        multiple
                                                        :accept="
                                                            supportedEpisodeAccept
                                                        "
                                                        class="sr-only"
                                                        :disabled="
                                                            wizard.isRecovering
                                                                .value
                                                        "
                                                        :aria-label="`Select all remaining files for ${batch.series.name}`"
                                                        @change="
                                                            selectRecoveryFiles(
                                                                $event,
                                                                batch,
                                                            )
                                                        "
                                                    />
                                                </label>
                                                <label
                                                    class="cursor-pointer rounded-md border px-3 py-2 text-xs font-medium"
                                                >
                                                    Select original folder
                                                    <input
                                                        type="file"
                                                        multiple
                                                        webkitdirectory
                                                        directory
                                                        :accept="
                                                            supportedEpisodeAccept
                                                        "
                                                        class="sr-only"
                                                        :disabled="
                                                            wizard.isRecovering
                                                                .value
                                                        "
                                                        :aria-label="`Select the original folder for ${batch.series.name}`"
                                                        @change="
                                                            selectRecoveryFiles(
                                                                $event,
                                                                batch,
                                                            )
                                                        "
                                                    />
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <p
                                        v-if="wizard.recoveryError.value"
                                        class="mt-3 text-sm text-destructive"
                                        role="alert"
                                    >
                                        {{ wizard.recoveryError.value }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <SourceSelectionStep
                            :total-selected="wizard.selectedFiles.value.length"
                            :blocking-issues="wizard.blockingIssues.value"
                            :excluded-issues="wizard.excludedIssues.value"
                            @select="wizard.selectSources"
                        />
                    </div>

                    <IdentifyShowStep
                        v-else-if="wizard.currentStep.value === 2"
                        v-model:search-input="wizard.searchInput.value"
                        :source-name="wizard.sourceName.value"
                        :results="wizard.results.value"
                        :selected-series="wizard.selectedSeries.value"
                        :parsed-source="wizard.parsedSource.value"
                        :category="wizard.category.value"
                        :is-looking-up="wizard.isLookingUp.value"
                        :is-confirming="wizard.isConfirming.value"
                        :lookup-completed="wizard.lookupCompleted.value"
                        :error-message="wizard.lookupError.value"
                        @search="wizard.runSmartSearch"
                        @select="wizard.selectSeries"
                        @confirm="wizard.confirmSeries"
                        @category="wizard.setCategory"
                    />

                    <ReviewEpisodesStep
                        v-else-if="
                            wizard.currentStep.value === 3 &&
                            wizard.confirmedSeries.value
                        "
                        :series="wizard.confirmedSeries.value"
                        :groups="wizard.reviewGroups.value"
                        :counts="wizard.reviewCounts.value"
                        :ready="wizard.isReviewReady.value"
                        :season-hydration-states="
                            wizard.seasonHydrationStates.value
                        "
                        :season-hydration-errors="
                            wizard.seasonHydrationErrors.value
                        "
                        :preview-bulk-assignment="wizard.previewBulkAssignment"
                        @set-season="wizard.setReviewSeason"
                        @set-episode="wizard.setReviewEpisode"
                        @set-replacement="wizard.setReplacementConfirmed"
                        @hydrate-season="wizard.hydrateSeason"
                        @apply-bulk="wizard.applyBulkAssignment"
                        @continue="wizard.confirmEpisodeReview"
                    />

                    <StorageStep
                        v-else-if="
                            wizard.currentStep.value === 4 &&
                            wizard.confirmedSeries.value &&
                            wizard.reviewedMappings.value
                        "
                        :series="wizard.confirmedSeries.value"
                        :preview="wizard.storagePreview.value"
                        :selected-disk-id="wizard.selectedDiskId.value"
                        :is-loading="wizard.isStorageLoading.value"
                        :is-busy="wizard.isAdmitting.value"
                        :error-message="wizard.storageError.value"
                        :fingerprint-progress="wizard.fingerprintProgress.value"
                        @choose="wizard.prepareBatch"
                        @retry="wizard.retryStorage"
                    />

                    <div
                        v-else-if="
                            wizard.currentStep.value === 5 &&
                            wizard.admittedBatch.value
                        "
                        class="flex flex-col gap-6"
                    >
                        <UploadStep
                            :batch="wizard.admittedBatch.value"
                            :items="wizard.queueItems.value"
                            :active-item="wizard.activeQueueItem.value"
                            :overall-confirmed-bytes="
                                wizard.overallConfirmedBytes.value
                            "
                            :resolved-count="wizard.resolvedCount.value"
                            :connection-state="wizard.connectionState.value"
                            :speed-bytes-per-second="
                                wizard.speedBytesPerSecond.value
                            "
                            :eta-seconds="wizard.etaSeconds.value"
                            :error-message="wizard.uploadError.value"
                            @pause="wizard.pauseUpload"
                            @retry-transfer="wizard.retryUpload"
                            @retry-validation="wizard.retryValidation"
                            @retry-reconciliation="
                                wizard.retryBatchReconciliation
                            "
                            @skip="wizard.skipCurrentEpisode"
                        />
                        <UploadResultNotificationControl
                            subject="Show"
                            :state="notifications.state.value"
                            :error-message="notifications.requestError.value"
                            @enable="notifications.requestPermission"
                            @disable="notifications.disableNotifications"
                            @test="notifications.sendTestNotification"
                        />
                    </div>

                    <CompletionStep
                        v-else-if="
                            wizard.currentStep.value === 6 &&
                            wizard.admittedBatch.value
                        "
                        :batch="wizard.admittedBatch.value"
                        :items="wizard.queueItems.value"
                        :completed-count="wizard.completedCount.value"
                        :skipped-count="wizard.skippedCount.value"
                        @upload-more="wizard.uploadMoreEpisodes"
                    />
                </div>

                <footer
                    class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t bg-card px-4 py-3 sm:px-6"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <img
                            src="/images/tmdb-logo.svg"
                            alt="The Movie Database (TMDB)"
                            class="hidden h-auto w-20 sm:block"
                        />
                        <p
                            class="max-w-52 text-[10px] leading-4 text-muted-foreground"
                        >
                            Uses the TMDB API; not endorsed or certified by
                            TMDB.
                        </p>
                    </div>

                    <div
                        class="ml-auto flex flex-wrap items-center justify-end gap-2"
                    >
                        <Button
                            v-if="wizard.currentStep.value === 1"
                            variant="ghost"
                            as-child
                        >
                            <Link :href="dashboard()">
                                <ArrowLeft class="size-4" /> Dashboard
                            </Link>
                        </Button>
                        <Button
                            v-if="
                                wizard.canKeepCurrentVideos.value &&
                                !wizard.admissionStarted.value
                            "
                            type="button"
                            @click="wizard.keepCurrentVideos"
                        >
                            <RotateCcw class="size-4" /> Keep current videos
                        </Button>
                        <Button
                            v-if="
                                (wizard.currentStep.value === 2 ||
                                    wizard.currentStep.value === 3 ||
                                    wizard.currentStep.value === 4) &&
                                !wizard.admissionStarted.value
                            "
                            type="button"
                            variant="outline"
                            @click="wizard.goToSource"
                        >
                            <FilePenLine class="size-4" /> Change videos
                        </Button>
                        <Button
                            v-if="
                                wizard.currentStep.value === 4 &&
                                !wizard.admissionStarted.value
                            "
                            type="button"
                            variant="outline"
                            @click="wizard.returnToReview"
                        >
                            <ListChecks class="size-4" /> Review episodes
                        </Button>
                        <Button
                            v-if="
                                (wizard.currentStep.value === 3 ||
                                    wizard.currentStep.value === 4) &&
                                !wizard.admissionStarted.value
                            "
                            type="button"
                            @click="wizard.changeShow"
                        >
                            <Tv2 class="size-4" /> Change show
                        </Button>
                    </div>
                </footer>
            </div>
        </section>
    </div>
</template>
