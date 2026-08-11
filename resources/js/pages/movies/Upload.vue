<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    FilePenLine,
    Film,
    LoaderCircle,
    Pause,
    Play,
    RotateCcw,
    XCircle,
} from '@lucide/vue';
import { nextTick, watch } from 'vue';
import CompletionStep from '@/components/movie-upload/CompletionStep.vue';
import IdentifyMovieStep from '@/components/movie-upload/IdentifyMovieStep.vue';
import SourceFileStep from '@/components/movie-upload/SourceFileStep.vue';
import StorageStep from '@/components/movie-upload/StorageStep.vue';
import UploadStep from '@/components/movie-upload/UploadStep.vue';
import WizardProgress from '@/components/movie-upload/WizardProgress.vue';
import { Button } from '@/components/ui/button';
import { useMovieUploadWizard } from '@/composables/useMovieUploadWizard';
import { useUploadResultNotifications } from '@/composables/useUploadResultNotifications';
import { dashboard } from '@/routes';
import { show as movieDetails, upload as movieUpload } from '@/routes/movies';

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
const uploadNotifications = useUploadResultNotifications();

watch(
    () => ({
        uuid: wizard.reservation.value?.uuid,
        mediaItemId: wizard.reservation.value?.media_item_id,
        status: wizard.reservation.value?.status,
        failureDetail: wizard.reservation.value?.failure?.detail,
    }),
    (session, previousSession) => {
        if (
            !session.uuid ||
            !session.mediaItemId ||
            session.uuid !== previousSession?.uuid ||
            session.status === previousSession.status
        ) {
            return;
        }

        const movieName =
            wizard.confirmedMovie.value?.data.title ||
            wizard.reservation.value?.original_filename ||
            'Movie';
        const mediaItemId = session.mediaItemId;

        if (session.status === 'completed') {
            uploadNotifications.notifyUploadResult({
                uploadUuid: session.uuid,
                result: 'completed',
                body: movieName,
                onClick: () => router.visit(movieDetails.url(mediaItemId)),
            });
        }

        if (session.status === 'failed') {
            const failureDetail =
                session.failureDetail?.trim() ||
                'The upload could not be processed. Open this page to review retry or discard options.';

            uploadNotifications.notifyUploadResult({
                uploadUuid: session.uuid,
                result: 'failed',
                body: `${movieName}: ${failureDetail}`,
            });
        }
    },
);

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
                :has-reservation="wizard.reservation.value !== null"
            />

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="shrink-0 border-b px-4 py-3 sm:px-6">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Film class="size-4" />
                        </span>
                        <h1 class="truncate text-lg font-semibold">
                            Upload movie
                        </h1>
                        <span
                            class="ml-auto shrink-0 text-xs font-medium text-muted-foreground lg:hidden"
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
                        :selected-movie="wizard.selectedMovie.value"
                        :parsed-filename="wizard.parsedFilename.value"
                        :is-looking-up="wizard.isLookingUp.value"
                        :is-confirming="wizard.isConfirming.value"
                        :lookup-completed="wizard.lookupCompleted.value"
                        :error-message="wizard.lookupError.value"
                        @search="wizard.runSmartSearch"
                        @select="wizard.selectMovie"
                        @confirm="wizard.confirmMovie"
                    />
                    <StorageStep
                        v-else-if="
                            wizard.currentStep.value === 3 &&
                            wizard.confirmedMovie.value
                        "
                        v-model:replacement-confirmed="
                            wizard.replacementConfirmed.value
                        "
                        :movie="wizard.confirmedMovie.value"
                        :preview="wizard.pathPreview.value"
                        :selected-disk-id="wizard.selectedDiskId.value"
                        :is-checking="wizard.isCheckingDestination.value"
                        :is-busy="wizard.isAdmissionBusy.value"
                        :is-hashing="wizard.isHashing.value"
                        :is-reserving="wizard.isReserving.value"
                        :error-message="
                            wizard.previewError.value ||
                            wizard.reservationError.value
                        "
                        @choose="wizard.selectStorageAndStart"
                        @retry="wizard.requestPathPreview"
                    />
                    <UploadStep
                        v-else-if="
                            wizard.currentStep.value === 4 &&
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
                        :notification-state="uploadNotifications.state.value"
                        :notification-error="
                            uploadNotifications.requestError.value
                        "
                        @enable-notifications="
                            uploadNotifications.requestPermission
                        "
                        @disable-notifications="
                            uploadNotifications.disableNotifications
                        "
                        @test-notifications="
                            uploadNotifications.sendTestNotification
                        "
                    />
                    <CompletionStep
                        v-else-if="
                            wizard.currentStep.value === 5 &&
                            wizard.reservation.value
                        "
                        :session="wizard.reservation.value"
                        :movie-title="
                            wizard.confirmedMovie.value?.data.title ||
                            wizard.reservation.value.original_filename
                        "
                        @another="wizard.beginNewUpload"
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
                            v-if="
                                wizard.currentStep.value === 1 &&
                                !wizard.sourceFile.value
                            "
                            variant="ghost"
                            as-child
                        >
                            <Link :href="dashboard()"
                                ><ArrowLeft class="size-4" /> Dashboard</Link
                            >
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
                            Keep selected file
                        </Button>
                        <Button
                            v-if="wizard.currentStep.value === 2"
                            type="button"
                            variant="outline"
                            @click="wizard.goToSource"
                        >
                            <FilePenLine class="size-4" /> Change file
                        </Button>
                        <template v-if="wizard.currentStep.value === 3">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="wizard.isAdmissionBusy.value"
                                @click="wizard.goToSource"
                            >
                                <FilePenLine class="size-4" /> Change file
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="wizard.isAdmissionBusy.value"
                                @click="wizard.changeMovie"
                            >
                                <RotateCcw class="size-4" /> Change movie
                            </Button>
                        </template>
                        <template
                            v-if="
                                wizard.currentStep.value === 4 &&
                                wizard.reservation.value
                            "
                        >
                            <Button
                                v-if="
                                    wizard.reservation.value.status ===
                                    'cancelled'
                                "
                                type="button"
                                @click="wizard.beginNewUpload"
                            >
                                <RotateCcw class="size-4" /> Upload another
                                movie
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
                                    <RotateCcw v-else class="size-4" /> Retry
                                    validation
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
                                    <XCircle v-else class="size-4" /> Discard
                                    failed upload
                                </Button>
                            </template>
                            <template
                                v-else-if="
                                    !['processing', 'completed'].includes(
                                        wizard.reservation.value.status,
                                    )
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
                                    <Pause v-else class="size-4" /> Pause
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    :disabled="wizard.isCancelling.value"
                                    @click="wizard.retryUpload"
                                >
                                    <Play class="size-4" />
                                    {{
                                        wizard.connectionState.value === 'error'
                                            ? 'Retry upload'
                                            : 'Resume upload'
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
                                    <XCircle v-else class="size-4" /> Cancel
                                    upload
                                </Button>
                            </template>
                        </template>
                    </div>
                </footer>
            </div>
        </section>
    </div>
</template>
