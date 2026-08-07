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

it('exposes the ordered five-stage roadmap and unlocks upload after reservation', function () {
    $progress = file_get_contents(resource_path('js/components/movie-upload/WizardProgress.vue'));

    expect($progress)
        ->toContain("title: 'Source file'")
        ->toContain("title: 'Identify movie'")
        ->toContain("title: 'Check destination'")
        ->toContain("title: 'Reserve capacity'")
        ->toContain("title: 'Upload'")
        ->toContain("'Choose eligible storage'")
        ->toContain('locked: !props.canEnterCapacity')
        ->toContain("'Protected resumable transfer'")
        ->toContain('locked: !props.hasReservation')
        ->toContain(':aria-current=')
        ->toContain(':aria-disabled=');

    expect(strpos($progress, "title: 'Source file'"))
        ->toBeLessThan(strpos($progress, "title: 'Identify movie'"))
        ->and(strpos($progress, "title: 'Identify movie'"))
        ->toBeLessThan(strpos($progress, "title: 'Check destination'"))
        ->and(strpos($progress, "title: 'Check destination'"))
        ->toBeLessThan(strpos($progress, "title: 'Reserve capacity'"))
        ->and(strpos($progress, "title: 'Reserve capacity'"))
        ->toBeLessThan(strpos($progress, "title: 'Upload'"));
});

it('keeps the file local and protects wizard state from stale requests', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));

    expect($wizard)
        ->toContain('sourceFile.value = file')
        ->toContain('currentStep.value = 2')
        ->toContain("normalize('NFC')")
        ->toContain('MovieController.suggestions.url()')
        ->toContain('MovieController.search.url()')
        ->toContain('MovieController.showTmdb.url')
        ->toContain('MovieController.showImdb.url')
        ->toContain('MovieController.confirm.url()')
        ->toContain('MoviePathPreviewController.url')
        ->toContain('MovieUploadController.store.url')
        ->toContain('UploadController.destroy.url')
        ->toContain('requestId !== lookupRequestId')
        ->toContain('requestId !== confirmationRequestId')
        ->toContain('requestId !== previewRequestId')
        ->toContain('requestId !== reservationRequestId')
        ->toContain('selectedDiskId.value !== diskId')
        ->toContain('keepsConfirmedIdentity')
        ->toContain('await requestPathPreview()')
        ->toContain('crypto.randomUUID()')
        ->toContain('crypto.subtle.digest(')
        ->toContain('source.slice(0, firstEnd)')
        ->toContain('source.slice(lastStart, source.size)')
        ->toContain('preview.fingerprint_window_bytes')
        ->not->toContain('FormData')
        ->not->toContain('localStorage')
        ->not->toContain('sessionStorage')
        ->not->toContain('sourceFile.value.arrayBuffer')
        ->not->toContain('sourceFile.value.stream');
});

it('uses an explicit page-local resumable tus uploader with recovery controls', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));
    $uploadStep = file_get_contents(resource_path('js/components/movie-upload/UploadStep.vue'));
    $sourceStep = file_get_contents(resource_path('js/components/movie-upload/SourceFileStep.vue'));

    expect($wizard)
        ->toContain("from 'tus-js-client'")
        ->toContain('new TusUpload(source')
        ->toContain('uploadDataDuringCreation: false')
        ->toContain('parallelUploads: 1')
        ->toContain('storeFingerprintForResuming: false')
        ->toContain('removeFingerprintOnSuccess: false')
        ->toContain('onBeforeRequest: async')
        ->toContain('await refreshAuthorization()')
        ->toContain('activeTusUpload.abort(false)')
        ->toContain('UploadAuthorizationController.url')
        ->toContain('UploadPauseController.url')
        ->toContain('UploadController.index.url()')
        ->toContain('UploadController.show.url')
        ->toContain('UploadController.retry.url')
        ->toContain('scheduleProcessingPoll')
        ->toContain('clearProcessingPoll')
        ->toContain('openRetainedSession')
        ->toContain('currentStep.value = 5')
        ->not->toContain('localStorage')
        ->not->toContain('sessionStorage')
        ->and($page)
        ->toContain("'Start upload'")
        ->toContain("'Resume / retry'")
        ->toContain('@click="wizard.pauseUpload"')
        ->toContain('@click="wizard.cancelReservation"')
        ->and($uploadStep)
        ->toContain('Movie upload progress')
        ->toContain('Rolling speed')
        ->toContain('Estimated time')
        ->toContain('Validating media')
        ->toContain('Movie ready in Jellyfin storage')
        ->toContain('Finalized media technical metadata')
        ->toContain('session.finalized.video')
        ->toContain('session.finalized.audio')
        ->toContain('Protected staging destination')
        ->and($sourceStep)
        ->toContain('Continue an upload')
        ->toContain('Reselect & resume')
        ->toContain('Open validation')
        ->toContain('Review failure')
        ->toContain('without reselection');
});

it('renders accessible capacity selection and keeps immutable controls hidden after admission', function () {
    $capacity = file_get_contents(resource_path('js/components/movie-upload/CapacityStep.vue'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));

    expect($capacity)
        ->toContain('type="radio"')
        ->toContain('name="reservation-disk"')
        ->toContain(':disabled="!disk.eligible || isBusy"')
        ->toContain('peer-focus-visible:ring-2')
        ->toContain('Recommended')
        ->toContain('Active reservations')
        ->toContain('Projected usable')
        ->toContain('Safety reserve')
        ->toContain('dark:text-emerald-300')
        ->toContain('No movie bytes have been')
        ->not->toContain('authorization.token');

    expect($page)
        ->toContain('v-if="!wizard.reservation.value"')
        ->toContain('@click="wizard.reserveCapacity"')
        ->toContain('@click="wizard.cancelReservation"')
        ->toContain(':disabled="wizard.isAdmissionBusy.value"');
});

it('keeps wizard controls fixed around an internally scrolling content pane', function () {
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));
    $destination = file_get_contents(resource_path('js/components/movie-upload/DestinationStep.vue'));
    $progress = file_get_contents(resource_path('js/components/movie-upload/WizardProgress.vue'));
    $appLayout = file_get_contents(resource_path('js/layouts/AppLayout.vue'));
    $sidebarLayout = file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));
    $headerLayout = file_get_contents(resource_path('js/layouts/app/AppHeaderLayout.vue'));

    expect($page)
        ->toContain('contentClass:')
        ->toContain("'h-[100svh] overflow-hidden md:h-[calc(100svh-1rem)]'")
        ->toContain('h-0 min-h-0 flex-1')
        ->toContain('data-testid="wizard-content-pane"')
        ->toContain('min-h-0 flex-1 overflow-y-auto')
        ->toContain('class="shrink-0 border-b')
        ->toContain('class="flex shrink-0')
        ->toContain('Step {{ wizard.currentStep.value }} of 5')
        ->toContain('This product uses the TMDB API')
        ->toContain('not endorsed')
        ->toContain('certified by TMDB')
        ->toContain('focus({ preventScroll: true })');

    expect($progress)->toContain('class="hidden w-64 shrink-0');

    expect($appLayout)
        ->toContain('contentClass?: string')
        ->toContain(':content-class="contentClass"');

    expect($sidebarLayout)
        ->toContain('contentClass?: string')
        ->toContain('class="overflow-x-hidden"')
        ->toContain(':class="contentClass"');

    expect($headerLayout)
        ->toContain('contentClass?: string')
        ->toContain(':class="contentClass"');

    expect($destination)
        ->toContain('A new ordinary upload is blocked globally')
        ->toContain('Exact relative Jellyfin destination')
        ->toContain('Configured disk targets')
        ->toContain('preview.blockers')
        ->toContain('preview.disks');
});

it('requires exact irreversible confirmation and explains both replacement finalization modes', function () {
    $wizard = file_get_contents(resource_path('js/composables/useMovieUploadWizard.ts'));
    $types = file_get_contents(resource_path('js/types/movie-upload.ts'));
    $destination = file_get_contents(resource_path('js/components/movie-upload/DestinationStep.vue'));
    $capacity = file_get_contents(resource_path('js/components/movie-upload/CapacityStep.vue'));
    $upload = file_get_contents(resource_path('js/components/movie-upload/UploadStep.vue'));
    $page = file_get_contents(resource_path('js/pages/movies/Upload.vue'));

    expect($types)
        ->toContain("'replaceable'")
        ->toContain('can_replace_current_primary: boolean')
        ->toContain('replaceable: ReplaceableMediaFile | null')
        ->toContain("'atomic_same_path_swap'")
        ->toContain("'finalize_then_delete'")
        ->and($wizard)
        ->toContain('const replacementConfirmed = ref(false)')
        ->toContain('replacementConfirmed.value = false')
        ->toContain('replaces_media_file_id')
        ->toContain('replacement_confirmed')
        ->toContain('preview.can_replace_current_primary && !replacementConfirmed.value')
        ->and($destination)
        ->toContain('Tracked current primary is safely replaceable')
        ->toContain('old file remains untouched')
        ->toContain('preview.replaceable.relative_path')
        ->and($capacity)
        ->toContain('type="checkbox"')
        ->toContain('v-model="replacementConfirmed"')
        ->toContain('irreversible and keeps')
        ->toContain('atomic same-path swap')
        ->toContain('deletes only the exact old file')
        ->and($page)
        ->toContain('Confirm replacement &amp; reserve')
        ->toContain("? 'destructive'")
        ->and($upload)
        ->toContain('session.replacement.relative_path')
        ->toContain('Current primary replaced without a backup');
});
