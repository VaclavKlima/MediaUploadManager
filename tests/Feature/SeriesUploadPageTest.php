<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires authentication for the show upload wizard', function () {
    $this->get(route('series.upload'))->assertRedirect(route('login'));
});

it('renders the dedicated show upload wizard for authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('series.upload'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('series/Upload')
            ->missing('seriesRoots')
            ->has('fingerprintWindowBytes'));
});

it('exposes the ordered six-step roadmap with unfinished steps locked', function () {
    $progress = file_get_contents(resource_path('js/components/series-upload/WizardProgress.vue'));

    expect($progress)
        ->toContain("title: 'Select episodes'")
        ->toContain("title: 'Choose show'")
        ->toContain("title: 'Review episodes'")
        ->toContain("title: 'Choose storage'")
        ->toContain("title: 'Upload and validate'")
        ->toContain("title: 'Complete'")
        ->toContain(':aria-current=')
        ->toContain(':aria-disabled=')
        ->toContain('aria-label="Show upload progress"')
        ->toContain('locked: !props.hasValidSource')
        ->toContain('locked: !props.hasConfirmedShow')
        ->toContain('locked: !props.hasConfirmedReview')
        ->toContain('locked: !props.hasAdmittedBatch')
        ->toContain('locked: !props.hasCompletedBatch');

    expect(strpos($progress, "title: 'Select episodes'"))
        ->toBeLessThan(strpos($progress, "title: 'Choose show'"))
        ->and(strpos($progress, "title: 'Choose show'"))
        ->toBeLessThan(strpos($progress, "title: 'Review episodes'"))
        ->and(strpos($progress, "title: 'Review episodes'"))
        ->toBeLessThan(strpos($progress, "title: 'Choose storage'"))
        ->and(strpos($progress, "title: 'Choose storage'"))
        ->toBeLessThan(strpos($progress, "title: 'Upload and validate'"))
        ->and(strpos($progress, "title: 'Upload and validate'"))
        ->toBeLessThan(strpos($progress, "title: 'Complete'"));
});

it('uses the responsive full-height wizard shell with identification and review steps', function () {
    $page = file_get_contents(resource_path('js/pages/series/Upload.vue'));

    expect($page)
        ->toContain("contentClass: 'h-[100svh] overflow-hidden md:h-[calc(100svh-1rem)]'")
        ->toContain('data-testid="wizard-content-pane"')
        ->toContain('class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4 sm:p-6"')
        ->toContain('class="ml-auto shrink-0 text-xs font-medium text-muted-foreground lg:hidden"')
        ->toContain('Step {{ wizard.currentStep.value }} of 6')
        ->toContain('aria-live="polite"')
        ->toContain('?.focus({ preventScroll: true })')
        ->toContain('<IdentifyShowStep')
        ->toContain('<ReviewEpisodesStep')
        ->toContain('<StorageStep')
        ->toContain('<UploadStep')
        ->toContain('<CompletionStep')
        ->toContain(':has-confirmed-show=')
        ->toContain(':has-confirmed-review=')
        ->toContain('Change videos')
        ->toContain('@click="wizard.goToSource"')
        ->toContain('Keep current videos')
        ->toContain('Change show')
        ->toContain('tmdb-logo.svg');
});

it('provides accessible folder and multi-file source choosers for supported videos', function () {
    $source = file_get_contents(resource_path('js/components/series-upload/SourceSelectionStep.vue'));
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));

    expect($source)
        ->toContain('Choose folder')
        ->toContain('Choose episode files')
        ->toContain('webkitdirectory')
        ->toContain('directory')
        ->toContain('multiple')
        ->toContain(':accept="supportedEpisodeAccept"')
        ->toContain('aria-label="Choose an episode, season, or complete show folder"')
        ->toContain('aria-label="Choose individual episode video files"')
        ->toContain('aria-describedby="supported-episode-formats"')
        ->and($wizard)
        ->toContain("'.mkv,.mp4,.m4v,.avi,.mov,.ts,.m2ts,.webm'");
});

it('admits unresolved supported videos while retaining hard local safety exclusions', function () {
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));
    $matcher = file_get_contents(resource_path('js/lib/seriesEpisodeMatcher.ts'));

    expect($wizard)
        ->toContain("file.name.normalize('NFC')")
        ->toContain('knownExtraPattern')
        ->toContain('directIdentityCount > 1')
        ->toContain('joinedEpisodePattern.test(filename)')
        ->toContain('joinedCrossNotationPattern.test(filename)')
        ->toContain('multipartPattern.test(filename)')
        ->toContain('file.size === 0')
        ->toContain('new Map<string, number>()')
        ->toContain('sources.length > 1000')
        ->toContain('matchEpisodeHints(sources)')
        ->toContain('acceptedFiles.value.length > 0 && blockingIssues.value.length === 0')
        ->toContain('selectedFiles.value = [...files]')
        ->toContain('useHttp')
        ->toContain('suggestSeriesAction')
        ->not->toContain('crypto.subtle')
        ->not->toContain('FormData')
        ->not->toContain('localStorage')
        ->not->toContain('sessionStorage')
        ->not->toContain('.arrayBuffer(')
        ->not->toContain('.stream(')
        ->and($matcher)
        ->toContain('seasonEpisodePattern')
        ->toContain('crossNotationPattern')
        ->toContain('explicitEpisodePattern')
        ->toContain('/^Specials$/iu')
        ->toContain("normalize('NFC')")
        ->toContain('numeric: true');
});

it('keeps invalid source explanations on step one and advances valid selections automatically', function () {
    $source = file_get_contents(resource_path('js/components/series-upload/SourceSelectionStep.vue'));
    $page = file_get_contents(resource_path('js/pages/series/Upload.vue'));
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));

    expect($source)
        ->toContain('Fix before continuing')
        ->toContain("'file' : 'files' }} not")
        ->toContain('Nothing is hashed or uploaded in this step.')
        ->not->toContain('Selection summary')
        ->not->toContain('Detected episodes')
        ->and($page)
        ->not->toContain('>Continue<')
        ->not->toContain('wizard.goToChooseSeries')
        ->and($wizard)
        ->toContain('if (canContinue.value) {')
        ->toContain('currentStep.value = 2')
        ->toContain('loadFilenameSuggestions(sourceName.value, sourceRevision)')
        ->toContain('selectedFiles.value = [...files]');
});

it('provides a two-stage accessible show chooser with TV selected by default', function () {
    $identify = file_get_contents(resource_path('js/components/series-upload/IdentifyShowStep.vue'));
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));

    expect($identify)
        ->toContain('Choose show')
        ->toContain('role="radiogroup"')
        ->toContain('aria-label="Show category"')
        ->toContain('@click="$emit(\'category\', \'tv\')"')
        ->toContain('@click="$emit(\'category\', \'anime\')"')
        ->toContain(':aria-pressed=')
        ->toContain('selectedSeries?.tmdb_id === series.tmdb_id')
        ->toContain('Select ${series.name} and continue')
        ->toContain('series.original_name !== series.name')
        ->toContain('Loading show results')
        ->toContain('No show matches found')
        ->and($wizard)
        ->toContain("const category = ref<'tv' | 'anime'>('tv')")
        ->toContain("'IMDb lookup is not supported for shows.")
        ->toContain('showSeriesAction.url(tmdbId)')
        ->toContain('Number.isSafeInteger(tmdbId)');
});

it('guards stale lookup and confirmation responses while retaining navigation state', function () {
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));

    expect($wizard)
        ->toContain('textLookup.cancel()')
        ->toContain('filenameLookup.cancel()')
        ->toContain('detailsLookup.cancel()')
        ->toContain('confirmation.cancel()')
        ->toContain('requestId !== lookupRequestId')
        ->toContain('revision !== sourceRevision')
        ->toContain('category.value !== requestedCategory')
        ->toContain('selectedSeries.value?.tmdb_id !== series.tmdb_id')
        ->toContain("seasonNumbers.value.join(',') !== requestedSeasonKey")
        ->toContain('confirmation.season_numbers = requestedSeasons')
        ->toContain('currentStep.value = 3')
        ->toContain('confirmedSeries.value = response.data')
        ->toContain('hasReturnedToSource.value = true')
        ->toContain('currentStep.value = returnStepAfterSource')
        ->toContain('confirmedReviewKey === nextReviewKey')
        ->toContain('initializeReviewMappings(response.data)')
        ->toContain('revision !== sourceRevision')
        ->toContain('rowSelectionRevisions.get(sourceKey)')
        ->toContain('currentStep.value = returnStepAfterSource')
        ->toContain('Episode review reopened with every assignment unchanged.');
});

it('provides the complete accessible episode mapping editor contract', function () {
    $review = file_get_contents(resource_path('js/components/series-upload/ReviewEpisodesStep.vue'));
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));

    expect($review)
        ->toContain('Review episodes')
        ->toContain('Confirmed show and review summary')
        ->toContain('Filter episode review')
        ->toContain("['all', 'ready', 'attention']")
        ->toContain(':aria-pressed=')
        ->toContain('First unresolved file')
        ->toContain(':open="group.attentionCount > 0"')
        ->toContain('Assign in order')
        ->toContain('Starting episode')
        ->toContain('Preview:')
        ->toContain('Apply assignment')
        ->toContain('No existing manual assignment will be')
        ->toContain('Loading episode choices')
        ->toContain('Retry')
        ->toContain('aria-live="polite"')
        ->toContain('Season for ${row.source.filename}')
        ->toContain('Episode for ${row.source.filename}')
        ->toContain('Auto')
        ->toContain('Edited')
        ->toContain('Needs assignment')
        ->toContain('Conflict')
        ->toContain('Replacement required')
        ->toContain('type="checkbox"')
        ->toContain('Only its owner or an administrator')
        ->toContain('Confirm episode review')
        ->and($wizard)
        ->toContain('duplicateEpisodeHintSourceKeys')
        ->toContain("assignmentOrigin === 'manual'")
        ->toContain('episodeCounts.get(episode.id)')
        ->toContain('episode.can_replace_current_primary')
        ->toContain('mapping.replacesMediaFileId !==')
        ->toContain('episode.current_primary?.id')
        ->toContain('pendingSeasonHydrations')
        ->toContain('hydrateSeasonAction.url')
        ->toContain('request.cancel()');
});

it('retains exact reviewed mappings and opens live storage planning', function () {
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));
    $page = file_get_contents(resource_path('js/pages/series/Upload.vue'));
    $storage = file_get_contents(resource_path('js/components/series-upload/StorageStep.vue'));

    expect($wizard)
        ->toContain('reviewedMappings.value = reviewMappings.value.map')
        ->toContain('seriesEpisodeId: mapping.seriesEpisodeId as number')
        ->toContain('replacesMediaFileId: mapping.replacesMediaFileId')
        ->toContain('replacementConfirmed: mapping.replacementConfirmed')
        ->toContain('currentStep.value = 4')
        ->toContain('requestStoragePreview()')
        ->toContain('function returnToReview(): void')
        ->toContain('currentStep.value = 3')
        ->and($page)
        ->toContain('wizard.reviewConfirmed.value')
        ->toContain('@continue="wizard.confirmEpisodeReview"')
        ->toContain('@click="wizard.returnToReview"')
        ->toContain('@choose="wizard.prepareBatch"')
        ->toContain('@retry="wizard.retryStorage"')
        ->and($storage)
        ->toContain('Choose storage')
        ->toContain('projected_usable_bytes')
        ->toContain('Destinations and capacity details')
        ->toContain('aria-label="diskActionLabel(disk)"');
});

it('implements stable admission and the sequential episode transport queue', function () {
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));
    $transport = file_get_contents(resource_path('js/lib/uploadTransport.ts'));
    $upload = file_get_contents(resource_path('js/components/series-upload/UploadStep.vue'));
    $completion = file_get_contents(resource_path('js/components/series-upload/CompletionStep.vue'));

    expect($wizard)
        ->toContain('autoAdmissionAttemptedContext')
        ->toContain('idempotencyContextKey')
        ->toContain('fingerprintCache')
        ->toContain('fingerprintProgress')
        ->toContain('requestStoragePreview(true, true)')
        ->toContain('await startNextQueueItem()')
        ->toContain('authorizeAndStart(nextIndex)')
        ->toContain('nextSeriesQueueIndex(queueItems.value)')
        ->toContain("window.addEventListener('beforeunload', interruptActiveTransfer)")
        ->toContain('activeTusUpload.abort(false)')
        ->and($transport)
        ->toContain('fingerprintUploadFile')
        ->toContain('createUploadTransport')
        ->toContain('parallelUploads: 1')
        ->toContain('storeFingerprintForResuming: false')
        ->and($upload)
        ->toContain('Overall batch progress')
        ->toContain('Skip this episode')
        ->toContain('Retry validation')
        ->and($completion)
        ->toContain('Show upload complete')
        ->toContain('explicitly skipped');
});

it('provides verified reload recovery, stale callback guards, and all completion outcomes', function () {
    $wizard = file_get_contents(resource_path('js/composables/useSeriesUploadWizard.ts'));
    $queue = file_get_contents(resource_path('js/lib/seriesUploadQueue.ts'));
    $page = file_get_contents(resource_path('js/pages/series/Upload.vue'));
    $upload = file_get_contents(resource_path('js/components/series-upload/UploadStep.vue'));
    $completion = file_get_contents(resource_path('js/components/series-upload/CompletionStep.vue'));

    expect($wizard)
        ->toContain('indexSeriesBatchesAction.url()')
        ->toContain('recoverSeriesBatchAction.url(batch.uuid)')
        ->toContain('showSeriesBatchAction.url(batch.uuid)')
        ->toContain('queueGeneration')
        ->toContain('generation !== queueGeneration')
        ->toContain('initializeRecoveredQueue')
        ->toContain('await confirmBatchCompletion()')
        ->toContain('uploadMoreEpisodes')
        ->toContain('continuingSameShow')
        ->and($queue)
        ->toContain('normalizeRecoveryPath')
        ->toContain('matchSeriesRecoveryFiles')
        ->toContain('nextSeriesQueueIndex')
        ->toContain('aggregateSeriesQueueProgress')
        ->and($page)
        ->toContain('Resume an unfinished Show upload')
        ->toContain("useUploadResultNotifications('series')")
        ->toContain('subject="Show"')
        ->and($upload)
        ->toContain('Skip this episode?')
        ->toContain('Skip and continue')
        ->toContain('activeItem.batchItem.actions.cancel')
        ->toContain('resolvedCount')
        ->and($completion)
        ->toContain('Show upload completed with skips')
        ->toContain('Show batch skipped')
        ->toContain('View Shows')
        ->toContain('Upload more episodes');
});

it('uses Shows in all public Vue labels while preserving internal Series identifiers', function () {
    $publicFiles = [
        resource_path('js/components/AppSidebar.vue'),
        resource_path('js/pages/Dashboard.vue'),
        resource_path('js/pages/series/Index.vue'),
        resource_path('js/pages/series/Upload.vue'),
        resource_path('js/components/series-upload/SourceSelectionStep.vue'),
        resource_path('js/components/series-upload/WizardProgress.vue'),
        resource_path('js/components/series-upload/IdentifyShowStep.vue'),
        resource_path('js/components/series-upload/ReviewEpisodesStep.vue'),
        resource_path('js/components/series-upload/StorageStep.vue'),
        resource_path('js/components/series-upload/UploadStep.vue'),
        resource_path('js/components/series-upload/CompletionStep.vue'),
    ];

    $publicCopy = collect($publicFiles)->map(fn (string $file): string => file_get_contents($file))->join("\n");

    expect($publicCopy)
        ->toContain('Shows')
        ->toContain('Upload show episodes')
        ->not->toMatch('/[\'\">]Series(?:[\s<\'])/');
});
