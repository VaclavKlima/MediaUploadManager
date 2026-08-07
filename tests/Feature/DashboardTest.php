<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects the root to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('redirects guests from private pages to login', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with([
    'dashboard' => '/dashboard',
    'movie upload' => '/movies/upload',
    'profile settings' => '/settings/profile',
    'password settings' => '/settings/password',
]);

it('renders the dashboard for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));

    expect(file_get_contents(resource_path('js/pages/Dashboard.vue')))
        ->toContain('Upload movie')
        ->toContain(':href="movieUpload()"')
        ->not->toContain('MovieController')
        ->not->toContain('useHttp')
        ->not->toContain('type="file"');

    expect(file_get_contents(resource_path('js/components/AppSidebar.vue')))
        ->toContain("title: 'Upload movie'")
        ->toContain('href: movieUpload()');

    expect(file_get_contents(resource_path('js/components/AppLogoIcon.vue')))
        ->toContain('M24 33V16')
        ->and(file_get_contents(public_path('images/media-upload-manager-logo.svg')))
        ->toContain('Media Upload Manager')
        ->and(file_get_contents(base_path('README.md')))
        ->toContain('public/images/media-upload-manager-logo.svg')
        ->and(file_get_contents(resource_path('js/components/AppHeader.vue')))
        ->toContain('https://github.com/VaclavKlima/MediaUploadManager')
        ->not->toContain('laravel/vue-starter-kit');
});
