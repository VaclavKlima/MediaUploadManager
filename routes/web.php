<?php

use App\Http\Controllers\DiskController;
use App\Http\Controllers\InternalTusAuthorizationController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\MovieLibraryController;
use App\Http\Controllers\MoviePathPreviewController;
use App\Http\Controllers\MovieUploadController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\TusHookController;
use App\Http\Controllers\UploadAuthorizationController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UploadPauseController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::get('/', fn (): RedirectResponse => to_route('dashboard'))->name('home');

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
    Route::get('dashboard', fn (): Response => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('disks', DiskController::class)->name('disks.index');
    Route::get('movies', [MovieLibraryController::class, 'index'])->name('movies.index');
    Route::get('movies/upload', fn (): Response => Inertia::render('movies/Upload'))
        ->name('movies.upload');
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
