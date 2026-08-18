<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiskController;
use App\Http\Controllers\FolderCleanupController;
use App\Http\Controllers\InternalTusAuthorizationController;
use App\Http\Controllers\LibraryFindingController;
use App\Http\Controllers\LibraryScanController;
use App\Http\Controllers\LocalAgentLoginController;
use App\Http\Controllers\MissingMediaFileController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\MovieLibraryController;
use App\Http\Controllers\MoviePathPreviewController;
use App\Http\Controllers\MovieReidentificationController;
use App\Http\Controllers\MovieUploadController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Series\EpisodeRenameController;
use App\Http\Controllers\Series\SeriesBatchController;
use App\Http\Controllers\Series\SeriesCatalogController;
use App\Http\Controllers\Series\SeriesDetailsController;
use App\Http\Controllers\Series\SeriesLookupController;
use App\Http\Controllers\Series\SeriesMediaDeletionController;
use App\Http\Controllers\TusHookController;
use App\Http\Controllers\UploadAuthorizationController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UploadPauseController;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::get('/', fn (): RedirectResponse => to_route('dashboard'))->name('home');

Route::get('local/agent-login', LocalAgentLoginController::class)
    ->name('local.agent_login');

Route::get('internal/tus/authorize', InternalTusAuthorizationController::class)
    ->name('internal.tus.authorize');
Route::post('internal/tus/hooks', TusHookController::class)
    ->name('internal.tus.hooks');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('onboarding', [OnboardingController::class, 'edit'])->name('onboarding.edit');
    Route::put('onboarding', [OnboardingController::class, 'update'])
        ->middleware('throttle:credentials')
        ->name('onboarding.update');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('operations', function (Request $request): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAdministrator(), 403);

        return redirect()->route('pulse');
    })->name('operations');
    Route::get('disks', DiskController::class)->name('disks.index');
    Route::get('movies', [MovieLibraryController::class, 'index'])->name('movies.index');
    Route::get('movies/{mediaItem}', [MovieLibraryController::class, 'show'])
        ->whereNumber('mediaItem')
        ->missing(fn (): RedirectResponse => to_route('movies.index'))
        ->name('movies.show');
    Route::get('library-scans', [LibraryScanController::class, 'index'])->name('library_scans.index');
    Route::post('library-scans', [LibraryScanController::class, 'store'])->name('library_scans.store');
    Route::get('library-findings/{libraryFinding}/identity-preview', [LibraryFindingController::class, 'previewIdentity'])
        ->name('library_findings.identity_preview');
    Route::post('library-findings/{libraryFinding}/identify-import', [LibraryFindingController::class, 'identifyAndImport'])
        ->name('library_findings.identify_import');
    Route::post('library-findings/{libraryFinding}/import', [LibraryFindingController::class, 'queueImport'])
        ->name('library_findings.queue_import');
    Route::post('library-findings/{libraryFinding}/restore', [LibraryFindingController::class, 'restore'])
        ->name('library_findings.restore');
    Route::put('library-findings/{libraryFinding}/identity', [LibraryFindingController::class, 'identify'])
        ->name('library_findings.identify');
    Route::post('library-findings/imports', [LibraryFindingController::class, 'importMany'])
        ->name('library_findings.import');
    Route::delete('library-findings/{libraryFinding}', [LibraryFindingController::class, 'destroy'])
        ->name('library_findings.destroy');
    Route::post('library-findings/{libraryFinding}/cleanup-preview', [FolderCleanupController::class, 'preview'])
        ->name('library_findings.cleanup_preview');
    Route::post('folder-cleanups/{folderCleanup}/confirm', [FolderCleanupController::class, 'confirm'])
        ->name('folder_cleanups.confirm');
    Route::post('library-findings/{libraryFinding}/confirm-removed', MissingMediaFileController::class)
        ->name('library_findings.confirm_removed');
    Route::get('movies/upload', fn (): Response => Inertia::render('movies/Upload'))
        ->name('movies.upload');
    Route::get('series', [SeriesCatalogController::class, 'index'])->name('series.index');
    Route::get('series/upload', [SeriesCatalogController::class, 'upload'])->name('series.upload');
    Route::get('series/search', [SeriesLookupController::class, 'search'])
        ->middleware('throttle:tmdb')
        ->name('series.search');
    Route::get('series/suggestions', [SeriesLookupController::class, 'suggestions'])
        ->middleware('throttle:tmdb')
        ->name('series.suggestions');
    Route::get('series/tmdb/{tmdbId}', [SeriesLookupController::class, 'show'])
        ->whereNumber('tmdbId')
        ->middleware('throttle:tmdb')
        ->name('series.tmdb.show');
    Route::get('series/tmdb/{tmdbId}/seasons/{seasonNumber}', [SeriesLookupController::class, 'season'])
        ->whereNumber('tmdbId')
        ->whereNumber('seasonNumber')
        ->middleware('throttle:tmdb')
        ->name('series.tmdb.seasons.show');
    Route::post('series/confirm', [SeriesLookupController::class, 'confirm'])
        ->middleware('throttle:tmdb')
        ->name('series.confirm');
    Route::post('series/{series}/seasons/{seasonNumber}', [SeriesLookupController::class, 'hydrateSeason'])
        ->whereNumber('series')
        ->whereNumber('seasonNumber')
        ->middleware('throttle:tmdb')
        ->name('series.seasons.hydrate');
    Route::get('series/{series}', SeriesDetailsController::class)
        ->whereNumber('series')
        ->name('series.show');
    Route::post('series/{series}/seasons/{season}/episodes/{episode}/rename-preview', [EpisodeRenameController::class, 'preview'])
        ->scopeBindings()
        ->name('series.seasons.episodes.rename_preview');
    Route::patch('series/{series}/seasons/{season}/episodes/{episode}', [EpisodeRenameController::class, 'update'])
        ->scopeBindings()
        ->name('series.seasons.episodes.update');
    Route::delete('series/{series}/seasons/{season}/episodes/{episode}/media', [SeriesMediaDeletionController::class, 'episode'])
        ->scopeBindings()
        ->name('series.seasons.episodes.media.destroy');
    Route::delete('series/{series}/seasons/{season}/media', [SeriesMediaDeletionController::class, 'season'])
        ->scopeBindings()
        ->name('series.seasons.media.destroy');
    Route::delete('series/{series}', [SeriesMediaDeletionController::class, 'series'])
        ->whereNumber('series')
        ->name('series.destroy');
    Route::post('series/{series}/batches', [SeriesBatchController::class, 'store'])
        ->whereNumber('series')
        ->name('series.batches.store');
    Route::post('series/{series}/batches/preview', [SeriesBatchController::class, 'preview'])
        ->whereNumber('series')
        ->name('series.batches.preview');
    Route::get('series-batches/resumable', [SeriesBatchController::class, 'index'])
        ->name('series.batches.resumable');
    Route::get('series-batches/{seriesUploadBatch}', [SeriesBatchController::class, 'show'])
        ->name('series.batches.show');
    Route::post('series-batches/{seriesUploadBatch}/recovery', [SeriesBatchController::class, 'recovery'])
        ->name('series.batches.recovery');
    Route::post('movies/{mediaItem}/reidentification-preview', [MovieReidentificationController::class, 'preview'])
        ->middleware('throttle:tmdb')
        ->name('movies.reidentification.preview');
    Route::post('movies/{mediaItem}/reidentify', [MovieReidentificationController::class, 'store'])
        ->middleware('throttle:tmdb')
        ->name('movies.reidentify');
    Route::delete('movies/{mediaItem}', [MovieLibraryController::class, 'destroy'])
        ->name('movies.destroy');
    Route::get('movies/{mediaItem}/path-preview', MoviePathPreviewController::class)
        ->name('movies.path_preview');
    Route::post('movies/{mediaItem}/uploads', [MovieUploadController::class, 'store'])
        ->name('movies.uploads.store');
    Route::get('uploads/resumable', [UploadController::class, 'index'])
        ->name('uploads.resumable');
    Route::get('uploads/{upload}', [UploadController::class, 'show'])
        ->name('uploads.show');
    Route::post('uploads/{upload}/authorization', UploadAuthorizationController::class)
        ->name('uploads.authorization');
    Route::post('uploads/{upload}/pause', UploadPauseController::class)
        ->name('uploads.pause');
    Route::post('uploads/{upload}/processing/retry', [UploadController::class, 'retry'])
        ->name('uploads.processing.retry');
    Route::delete('uploads/{upload}', [UploadController::class, 'destroy'])
        ->name('uploads.destroy');

    Route::middleware('throttle:tmdb')->prefix('movies')->name('movies.')->controller(MovieController::class)->group(function () {
        Route::get('search', 'search')->name('search');
        Route::get('suggestions', 'suggestions')->name('suggestions');
        Route::get('tmdb/{tmdbId}', 'showTmdb')->whereNumber('tmdbId')->name('tmdb.show');
        Route::get('imdb/{imdbId}', 'showImdb')->where('imdbId', 'tt[0-9]{7,12}')->name('imdb.show');
        Route::post('confirm', 'confirm')->name('confirm');
    });
});

require __DIR__.'/settings.php';
