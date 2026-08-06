<?php

use App\Http\Controllers\DiskController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::get('/', fn (): RedirectResponse => to_route('dashboard'))->name('home');

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

    Route::middleware('throttle:tmdb')->prefix('movies')->name('movies.')->controller(MovieController::class)->group(function () {
        Route::get('search', 'search')->name('search');
        Route::get('suggestions', 'suggestions')->name('suggestions');
        Route::get('tmdb/{tmdbId}', 'showTmdb')->whereNumber('tmdbId')->name('tmdb.show');
        Route::get('imdb/{imdbId}', 'showImdb')->where('imdbId', 'tt[0-9]{7,12}')->name('imdb.show');
        Route::post('confirm', 'confirm')->name('confirm');
    });
});

require __DIR__.'/settings.php';
