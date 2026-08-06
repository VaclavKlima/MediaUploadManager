<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the private login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->missing('canResetPassword'));
});

it('authenticates a user with valid credentials', function () {
    Log::spy();
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    Log::shouldHaveReceived('notice')->once()->with('security.audit', [
        'event' => 'login_succeeded',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
    ]);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects a disabled user with the generic credential error', function () {
    Log::spy();
    $disabledUser = User::factory()->disabled()->create();

    $this->post(route('login.store'), [
        'email' => $disabledUser->email,
        'password' => 'password',
    ]);
    $disabledErrors = session('errors');

    $this->post(route('login.store'), [
        'email' => 'missing@example.com',
        'password' => 'password',
    ]);
    $invalidErrors = session('errors');

    expect($disabledErrors)->toBe($invalidErrors);
    $this->assertGuest();
    Log::shouldHaveReceived('notice')->once()->with('security.audit', [
        'event' => 'disabled_authentication_rejected',
        'user_id' => $disabledUser->id,
        'ip_address' => '127.0.0.1',
        'source' => 'login',
    ]);
});

it('terminates a previously authenticated disabled session', function () {
    $user = User::factory()->disabled()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('rate limits repeated login attempts', function () {
    $user = User::factory()->create();
    $key = md5('login'.implode('|', [$user->email, '127.0.0.1']));

    RateLimiter::increment($key, amount: 5);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ])->assertTooManyRequests();
});

it('logs out an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});
