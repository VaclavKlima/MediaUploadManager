<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires authentication for the movie upload wizard', function () {
    $this->get(route('movies.upload'))->assertRedirect(route('login'));
});

it('renders the dedicated movie upload wizard for authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('movies.upload'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('movies/Upload'));
});

it('exposes the simplified ordered five-step roadmap', function () {
    $progress = file_get_contents(resource_path('js/components/movie-upload/WizardProgress.vue'));

    expect($progress)
        ->toContain("title: 'Select file'")
        ->toContain("title: 'Choose movie'")
        ->toContain("title: 'Choose storage'")
        ->toContain("title: 'Upload and validate'")
        ->toContain("title: 'Complete'")
        ->toContain(':aria-current=')
        ->toContain(':aria-disabled=');

    expect(strpos($progress, "title: 'Select file'"))
        ->toBeLessThan(strpos($progress, "title: 'Choose movie'"))
        ->and(strpos($progress, "title: 'Choose movie'"))
        ->toBeLessThan(strpos($progress, "title: 'Choose storage'"))
        ->and(strpos($progress, "title: 'Choose storage'"))
        ->toBeLessThan(strpos($progress, "title: 'Upload and validate'"))
        ->and(strpos($progress, "title: 'Upload and validate'"))
        ->toBeLessThan(strpos($progress, "title: 'Complete'"));
});

it('selects and confirms movies inline without a details modal', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $identify = file_get_contents(resource_path('js/components/movie-upload/IdentifyMovieStep.vue'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));

    expect($identify)
        ->toContain('selectedMovie: MovieSummary | null')
        ->toContain(':aria-pressed=')
        ->toContain('blur-[1px]')
        ->toContain('opacity-40')
        ->toContain("'Select'")
        ->toContain('confirm: []')
        ->toContain('@click="$emit(\'confirm\')"')
        ->and($wizard)
        ->toContain('function selectMovie(movie: MovieSummary): void')
        ->toContain('results.value = [movie]')
        ->toContain('selectedMovie.value = null')
        ->toContain('MovieController.showTmdb.url')
        ->toContain('MovieController.showImdb.url')
        ->toContain('MovieController.confirm.url()')
        ->and($page)
        ->toContain('@select="wizard.selectMovie"')
        ->toContain('@confirm="wizard.confirmMovie"')
        ->not->toContain('MovieDetailsDialog');
});

it('keeps the file local and protects wizard state from stale requests', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));

    expect($wizard)
        ->toContain('sourceFile.value = file')
        ->toContain('currentStep.value = 2')
        ->toContain("normalize('NFC')")
        ->toContain('MovieController.suggestions.url()')
        ->toContain('MovieController.search.url()')
        ->toContain('MoviePathPreviewController.url')
        ->toContain('MovieUploadController.store.url')
        ->toContain('requestId !== lookupRequestId')
        ->toContain('requestId !== confirmationRequestId')
        ->toContain('requestId !== previewRequestId')
        ->toContain('requestId !== reservationRequestId')
        ->toContain('selectedDiskId.value !== diskId')
        ->toContain('crypto.randomUUID()')
        ->toContain('crypto.subtle.digest(')
        ->toContain('source.slice(0, firstEnd)')
        ->toContain('source.slice(lastStart, source.size)')
        ->not->toContain('FormData')
        ->not->toContain('localStorage')
        ->not->toContain('sessionStorage')
        ->not->toContain('sourceFile.value.arrayBuffer')
        ->not->toContain('sourceFile.value.stream');
});

it('combines preview and admission into explicit disk selection without preselection', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $storage = file_get_contents(resource_path('js/components/movie-upload/StorageStep.vue'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));

    expect($storage)
        ->toContain('Choose storage')
        ->toContain('Recommended')
        ->toContain('usable after upload')
        ->toContain('disk.reasons[0]?.message')
        ->toContain('Storage details')
        ->toContain('Safety reserve')
        ->toContain('Active reservations')
        ->toContain('@click="$emit(\'choose\', disk.id)"')
        ->and($wizard)
        ->toContain("selectedDiskId.value = ''")
        ->not->toContain('selectedDiskId.value = response.data.recommended_disk_id')
        ->not->toContain('selectedDiskId.value ||= pathPreview.value.recommended_disk_id')
        ->and($page)
        ->toContain('StorageStep')
        ->toContain('@choose="wizard.selectStorageAndStart"')
        ->not->toContain('DestinationStep')
        ->not->toContain('CapacityStep');
});

it('fingerprints reserves and starts a new upload from one eligible disk click', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));

    expect($wizard)
        ->toContain('async function selectStorageAndStart(diskId: string): Promise<void>')
        ->toContain('isAdmissionBusy.value')
        ->toContain('disk.id === diskId && disk.eligible')
        ->toContain('await fingerprintFile(')
        ->toContain('reservationRequest.disk_id = diskId')
        ->toContain('await reservationRequest.post(')
        ->toContain('currentStep.value = 4')
        ->toContain('await startUpload()');

    expect(strpos($wizard, 'await fingerprintFile('))
        ->toBeLessThan(strpos($wizard, 'await reservationRequest.post('))
        ->and(strpos($wizard, 'await reservationRequest.post('))
        ->toBeLessThan(strpos($wizard, 'await startUpload()'));
});

it('keeps admission failures double clicks and stale disk responses on storage', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $storage = file_get_contents(resource_path('js/components/movie-upload/StorageStep.vue'));

    expect($wizard)
        ->toContain('reservation.value ||')
        ->toContain('isAdmissionBusy.value ||')
        ->toContain('requestId !== reservationRequestId')
        ->toContain('selectedDiskId.value !== diskId')
        ->toContain('The file could not be fingerprinted')
        ->toContain('Capacity could not be reserved')
        ->toContain('Storage could not be loaded')
        ->toContain('onNetworkError: () =>')
        ->and($storage)
        ->toContain('role="alert"')
        ->toContain('Try again')
        ->toContain(':disabled="')
        ->toContain('!disk.eligible');
});

it('gates replacement disks behind exact irreversible confirmation and shows both methods', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $storage = file_get_contents(resource_path('js/components/movie-upload/StorageStep.vue'));

    expect($wizard)
        ->toContain('preview.can_replace_current_primary && !replacementConfirmed.value')
        ->toContain('replaces_media_file_id')
        ->toContain('replacement_confirmed')
        ->and($storage)
        ->toContain('type="checkbox"')
        ->toContain('v-model="replacementConfirmed"')
        ->toContain('preview.replaceable.disk.label')
        ->toContain('preview.replaceable.relative_path')
        ->toContain('preview.replaceable.size_bytes')
        ->toContain('preview.can_replace_current_primary')
        ->toContain('!replacementConfirmed')
        ->toContain('Atomic same-path replacement')
        ->toContain('Finalize, then remove old file');
});

it('keeps recovered upload validation and failure states on step four until server completion', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));
    $upload = file_get_contents(resource_path('js/components/movie-upload/UploadStep.vue'));

    expect($wizard)
        ->toContain("authorization.status === 'paused'")
        ->toContain('currentStep.value = 4')
        ->toContain("if (session.status === 'processing')")
        ->toContain('scheduleProcessingPoll(')
        ->toContain("if (session.status === 'completed')")
        ->toContain('currentStep.value = 5')
        ->toContain("if (session.status === 'failed')")
        ->and($page)
        ->toContain("'Resume upload'")
        ->toContain("'Retry upload'")
        ->not->toContain("'Start upload'")
        ->and($upload)
        ->toContain('Upload and validate')
        ->toContain('Validating media')
        ->toContain('Validation needs attention')
        ->toContain('Movie upload progress');

    expect(substr_count($wizard, 'currentStep.value = 5'))->toBe(1);
});

it('shows a dedicated completion summary with collapsed technical details and generated library navigation', function () {
    $completion = file_get_contents(resource_path('js/components/movie-upload/CompletionStep.vue'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));

    expect($completion)
        ->toContain('Upload complete')
        ->toContain('movieTitle')
        ->toContain('Destination')
        ->toContain('File size')
        ->toContain('Duration')
        ->toContain('Primary resolution')
        ->toContain('<details')
        ->toContain('Technical details')
        ->toContain('session.finalized.video')
        ->toContain('session.finalized.audio')
        ->toContain('Replacement history')
        ->toContain('Upload another movie')
        ->toContain('View movie library')
        ->toContain("import { index as movieLibrary } from '@/routes/movies'")
        ->toContain(':href="movieLibrary()"')
        ->and($page)
        ->toContain('CompletionStep')
        ->toContain('@another="wizard.beginNewUpload"');
});

it('uses a page-local resumable tus uploader with recovery and failure controls', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));
    $source = file_get_contents(resource_path('js/components/movie-upload/SourceFileStep.vue'));

    expect($wizard)
        ->toContain("from 'tus-js-client'")
        ->toContain('new TusUpload(source')
        ->toContain('uploadDataDuringCreation: false')
        ->toContain('parallelUploads: 1')
        ->toContain('storeFingerprintForResuming: false')
        ->toContain('onBeforeRequest: async')
        ->toContain('activeTusUpload.abort(false)')
        ->toContain('UploadAuthorizationController.url')
        ->toContain('UploadPauseController.url')
        ->toContain('UploadController.retry.url')
        ->and($page)
        ->toContain('@click="wizard.pauseUpload"')
        ->toContain('@click="wizard.cancelReservation"')
        ->toContain('@click="wizard.retryProcessing"')
        ->toContain('@click="wizard.cancelReservation"')
        ->and($source)
        ->toContain('Continue an upload')
        ->toContain('Reselect & resume')
        ->toContain('Open validation')
        ->toContain('Review failure');
});

it('shows discard failures in the failed upload pane', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $uploadStep = file_get_contents(resource_path('js/components/movie-upload/UploadStep.vue'));

    expect($wizard)
        ->toContain('const showCancellationError = (message: string): void =>')
        ->toContain('uploadError.value = message')
        ->toContain('onNetworkError: () =>')
        ->and($uploadStep)
        ->toContain('v-if="errorMessage"')
        ->toContain('{{ errorMessage }}');
});

it('returns to a clean source step after discarding a failed upload', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));

    expect($wizard)
        ->toContain('if (discardingFailure) {')
        ->toContain("'Failed upload discarded. Select a source file to begin.'")
        ->toContain('resetWizardForNewUpload(')
        ->toContain('resetReservationDraft(cancelActiveCancellation)')
        ->toContain('currentStep.value = 1')
        ->toContain('sourceFile.value = null')
        ->toContain('selectedMovie.value = null')
        ->toContain('confirmedMovie.value = null')
        ->toContain('pathPreview.value = null');
});

it('keeps controls fixed around a responsive internally scrolling content pane', function () {
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));
    $progress = file_get_contents(resource_path('js/components/movie-upload/WizardProgress.vue'));

    expect($page)
        ->toContain('contentClass:')
        ->toContain("'h-[100svh] overflow-hidden md:h-[calc(100svh-1rem)]'")
        ->toContain('data-testid="wizard-content-pane"')
        ->toContain('min-h-0 flex-1 overflow-y-auto')
        ->toContain('Step {{ wizard.currentStep.value }} of 5')
        ->toContain('Uses the TMDB API')
        ->toContain('not endorsed or certified by')
        ->toContain('focus({ preventScroll: true })')
        ->and($progress)
        ->toContain('class="hidden w-56 shrink-0');
});
