<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Database,
    FilePenLine,
    Film,
    LoaderCircle,
    Pause,
    Play,
    RotateCcw,
    XCircle,
} from '@lucide/vue';
import { nextTick, watch } from 'vue';
import CapacityStep from '@/components/movie-upload/CapacityStep.vue';
import DestinationStep from '@/components/movie-upload/DestinationStep.vue';
import IdentifyMovieStep from '@/components/movie-upload/IdentifyMovieStep.vue';
import MovieDetailsDialog from '@/components/movie-upload/MovieDetailsDialog.vue';
import SourceFileStep from '@/components/movie-upload/SourceFileStep.vue';
import UploadStep from '@/components/movie-upload/UploadStep.vue';
import WizardProgress from '@/components/movie-upload/WizardProgress.vue';
import { Button } from '@/components/ui/button';
import { useMovieUploadWizard } from '@/composables/useMovieUploadWizard';
import { dashboard } from '@/routes';
import { upload as movieUpload } from '@/routes/movies';

defineOptions({
    layout: {
        contentClass: 'h-[100svh] overflow-hidden md:h-[calc(100svh-1rem)]',
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Upload movie',
                href: movieUpload(),
            },
        ],
    },
});

const wizard = useMovieUploadWizard();

watch(wizard.currentStep, async (step) => {
    await nextTick();
    document
        .getElementById(`wizard-step-${step}`)
        ?.focus({ preventScroll: true });
});
</script>

<template>
    <div
        class="flex h-0 min-h-0 flex-1 flex-col overflow-hidden p-3 md:p-4 lg:p-6"
    >
        <Head title="Upload movie" />

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{ wizard.statusMessage.value }}
        </p>

        <section
            class="flex min-h-0 flex-1 overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
            aria-label="Upload movie wizard"
        >
            <WizardProgress
                :current-step="wizard.currentStep.value"
                :has-source="wizard.sourceFile.value !== null"
                :has-confirmed-movie="wizard.confirmedMovie.value !== null"
                :can-enter-capacity="
                    wizard.pathPreview.value?.can_start_new_upload === true ||
                    wizard.pathPreview.value?.can_replace_current_primary ===
                        true
                "
                :has-reservation="wizard.reservation.value !== null"
            />

            <div class="flex min-w-0 flex-1 flex-col">
                <header
                    class="shrink-0 border-b bg-gradient-to-r from-primary/10 via-card to-card px-4 py-3 sm:px-6 sm:py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                        >
                            <Film class="size-5" />
                        </span>
                        <div class="min-w-0">
                            <h1
                                class="truncate text-lg font-semibold sm:text-xl"
                            >
                                Upload movie
                            </h1>
                            <p
                                class="hidden text-sm text-muted-foreground sm:block"
                            >
                                Reserve safely, then stream resumable chunks
                                directly to protected storage.
                            </p>
                        </div>
                        <span
                            class="ml-auto shrink-0 rounded-full border bg-background px-3 py-1 text-xs font-medium lg:hidden"
                        >
                            Step {{ wizard.currentStep.value }} of 5
                        </span>
                    </div>
                </header>

                <div
                    data-testid="wizard-content-pane"
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4 sm:p-6"
                >
                    <SourceFileStep
                        v-if="wizard.currentStep.value === 1"
                        :filename="wizard.sourceFilename.value"
                        :resumable-sessions="wizard.resumableSessions.value"
                        :is-loading-sessions="wizard.isLoadingSessions.value"
                        :recovery-error="wizard.recoveryError.value"
                        @select="wizard.selectSource"
                        @recover="wizard.recoverSession"
                        @open="wizard.openRetainedSession"
                    />
                    <IdentifyMovieStep
                        v-else-if="wizard.currentStep.value === 2"
                        v-model:search-input="wizard.searchInput.value"
                        :source-filename="wizard.sourceFilename.value"
                        :results="wizard.results.value"
                        :parsed-filename="wizard.parsedFilename.value"
                        :is-looking-up="wizard.isLookingUp.value"
                        :lookup-completed="wizard.lookupCompleted.value"
                        :error-message="wizard.lookupError.value"
                        @search="wizard.runSmartSearch"
                        @inspect="wizard.inspectMovie"
                    />
                    <DestinationStep
                        v-else-if="
                            wizard.currentStep.value === 3 &&
                            wizard.confirmedMovie.value
                        "
                        :movie="wizard.confirmedMovie.value"
                        :source-filename="wizard.sourceFilename.value"
                        :preview="wizard.pathPreview.value"
                        :is-checking="wizard.isCheckingDestination.value"
                        :error-message="wizard.previewError.value"
                    />
                    <CapacityStep
                        v-else-if="
                            wizard.currentStep.value === 4 &&
                            wizard.confirmedMovie.value &&
                            wizard.pathPreview.value
                        "
                        v-model:selected-disk-id="wizard.selectedDiskId.value"
                        v-model:replacement-confirmed="
                            wizard.replacementConfirmed.value
                        "
                        :movie="wizard.confirmedMovie.value"
                        :preview="wizard.pathPreview.value"
                        :reservation="wizard.reservation.value"
                        :is-busy="wizard.isAdmissionBusy.value"
                        :error-message="wizard.reservationError.value"
                    />
                    <UploadStep
                        v-else-if="
                            wizard.currentStep.value === 5 &&
                            wizard.reservation.value
                        "
                        :session="wizard.reservation.value"
                        :connection-state="wizard.connectionState.value"
                        :transferred-bytes="wizard.transferredBytes.value"
                        :speed-bytes-per-second="
                            wizard.speedBytesPerSecond.value
                        "
                        :eta-seconds="wizard.etaSeconds.value"
                        :error-message="wizard.uploadError.value"
                    />
                </div>

                <footer
                    class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t bg-card px-4 py-3 sm:px-6"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <img
                            src="/images/tmdb-logo.svg"
                            alt="The Movie Database (TMDB)"
                            class="hidden h-auto w-24 sm:block"
                        />
                        <p
                            class="max-w-56 text-[11px] leading-4 text-muted-foreground"
                        >
                            This product uses the TMDB API but is not endorsed
                            or certified by TMDB.
                        </p>
                    </div>

                    <div class="ml-auto flex items-center gap-2">
                        <Button
                            v-if="
                                wizard.currentStep.value === 1 &&
                                !wizard.sourceFile.value
                            "
                            variant="ghost"
                            as-child
                        >
                            <Link :href="dashboard()">
                                <ArrowLeft class="size-4" />
                                Dashboard
                            </Link>
                        </Button>
                        <Button
                            v-if="
                                wizard.currentStep.value === 1 &&
                                wizard.sourceFile.value
                            "
                            type="button"
                            variant="outline"
                            @click="wizard.goToIdentify"
                        >
                            Keep current file
                        </Button>
                        <Button
                            v-if="wizard.currentStep.value === 2"
                            type="button"
                            variant="outline"
                            @click="wizard.goToSource"
                        >
                            <FilePenLine class="size-4" />
                            Change file
                        </Button>
                        <template v-if="wizard.currentStep.value === 3">
                            <Button
                                type="button"
                                variant="outline"
                                @click="wizard.goToSource"
                            >
                                <FilePenLine class="size-4" />
                                Change file
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="wizard.changeMovie"
                            >
                                <RotateCcw class="size-4" />
                                Change movie
                            </Button>
                            <Button
                                type="button"
                                :disabled="
                                    (!wizard.pathPreview.value
                                        ?.can_start_new_upload &&
                                        !wizard.pathPreview.value
                                            ?.can_replace_current_primary) ||
                                    wizard.isCheckingDestination.value
                                "
                                @click="wizard.goToCapacity"
                            >
                                <Database class="size-4" />
                                Choose storage
                            </Button>
                        </template>
                        <template v-if="wizard.currentStep.value === 4">
                            <template v-if="!wizard.reservation.value">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="wizard.isAdmissionBusy.value"
                                    @click="wizard.goToDestination"
                                >
                                    <ArrowLeft class="size-4" />
                                    Destination
                                </Button>
                                <Button
                                    type="button"
                                    :variant="
                                        wizard.pathPreview.value
                                            ?.can_replace_current_primary
                                            ? 'destructive'
                                            : 'default'
                                    "
                                    :disabled="
                                        !wizard.selectedDiskId.value ||
                                        (wizard.pathPreview.value
                                            ?.can_replace_current_primary &&
                                            !wizard.replacementConfirmed
                                                .value) ||
                                        wizard.isAdmissionBusy.value
                                    "
                                    @click="wizard.reserveCapacity"
                                >
                                    <LoaderCircle
                                        v-if="wizard.isAdmissionBusy.value"
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    <AlertTriangle
                                        v-else-if="
                                            wizard.pathPreview.value
                                                ?.can_replace_current_primary
                                        "
                                        class="size-4"
                                    />
                                    <Database v-else class="size-4" />
                                    <span v-if="wizard.isHashing.value">
                                        Fingerprinting…
                                    </span>
                                    <span v-else-if="wizard.isReserving.value">
                                        Reserving…
                                    </span>
                                    <span
                                        v-else-if="
                                            wizard.pathPreview.value
                                                ?.can_replace_current_primary
                                        "
                                    >
                                        Confirm replacement &amp; reserve
                                    </span>
                                    <span v-else>Reserve capacity</span>
                                </Button>
                            </template>
                            <Button
                                v-else
                                type="button"
                                variant="destructive"
                                :disabled="wizard.isCancelling.value"
                                @click="wizard.cancelReservation"
                            >
                                <LoaderCircle
                                    v-if="wizard.isCancelling.value"
                                    class="size-4 motion-safe:animate-spin"
                                />
                                <XCircle v-else class="size-4" />
                                {{
                                    wizard.isCancelling.value
                                        ? 'Cancelling…'
                                        : 'Cancel reservation'
                                }}
                            </Button>
                        </template>
                        <template
                            v-if="
                                wizard.currentStep.value === 5 &&
                                wizard.reservation.value
                            "
                        >
                            <Button
                                v-if="
                                    wizard.reservation.value.status ===
                                        'cancelled' ||
                                    wizard.reservation.value.status ===
                                        'completed'
                                "
                                type="button"
                                @click="wizard.beginNewUpload"
                            >
                                <RotateCcw class="size-4" />
                                Start another upload
                            </Button>
                            <template
                                v-else-if="
                                    wizard.reservation.value.status === 'failed'
                                "
                            >
                                <Button
                                    v-if="
                                        wizard.reservation.value.failure
                                            ?.can_retry
                                    "
                                    type="button"
                                    :disabled="
                                        wizard.isRetryingProcessing.value ||
                                        wizard.isCancelling.value
                                    "
                                    @click="wizard.retryProcessing"
                                >
                                    <LoaderCircle
                                        v-if="wizard.isRetryingProcessing.value"
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    <RotateCcw v-else class="size-4" />
                                    Retry validation
                                </Button>
                                <Button
                                    v-if="
                                        wizard.reservation.value.failure
                                            ?.can_discard
                                    "
                                    type="button"
                                    variant="destructive"
                                    :disabled="
                                        wizard.isRetryingProcessing.value ||
                                        wizard.isCancelling.value
                                    "
                                    @click="wizard.cancelReservation"
                                >
                                    <LoaderCircle
                                        v-if="wizard.isCancelling.value"
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    <XCircle v-else class="size-4" />
                                    Discard failed upload
                                </Button>
                            </template>
                            <template
                                v-else-if="
                                    ![
                                        'processing',
                                        'completed',
                                        'failed',
                                    ].includes(wizard.reservation.value.status)
                                "
                            >
                                <Button
                                    v-if="wizard.isUploadBusy.value"
                                    type="button"
                                    variant="outline"
                                    :disabled="wizard.isPausing.value"
                                    @click="wizard.pauseUpload"
                                >
                                    <LoaderCircle
                                        v-if="wizard.isPausing.value"
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    <Pause v-else class="size-4" />
                                    Pause
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    :disabled="wizard.isCancelling.value"
                                    @click="
                                        wizard.connectionState.value ===
                                            'paused' ||
                                        wizard.connectionState.value === 'error'
                                            ? wizard.retryUpload()
                                            : wizard.startUpload()
                                    "
                                >
                                    <Play class="size-4" />
                                    {{
                                        wizard.connectionState.value ===
                                            'paused' ||
                                        wizard.connectionState.value === 'error'
                                            ? 'Resume / retry'
                                            : 'Start upload'
                                    }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    :disabled="wizard.isCancelling.value"
                                    @click="wizard.cancelReservation"
                                >
                                    <LoaderCircle
                                        v-if="wizard.isCancelling.value"
                                        class="size-4 motion-safe:animate-spin"
                                    />
                                    <XCircle v-else class="size-4" />
                                    {{
                                        wizard.isCancelling.value
                                            ? 'Cancelling…'
                                            : 'Cancel upload'
                                    }}
                                </Button>
                            </template>
                        </template>
                    </div>
                </footer>
            </div>
        </section>

        <MovieDetailsDialog
            v-model:open="wizard.detailsOpen.value"
            :movie="wizard.selectedMovie.value"
            :is-confirming="wizard.isConfirming.value"
            @confirm="wizard.confirmMovie"
        />
    </div>
</template>
