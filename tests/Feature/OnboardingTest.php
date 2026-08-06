<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects an issued-credential user directly to onboarding after login', function () {
    $user = User::factory()->credentialChangeRequired()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('onboarding.edit', absolute: false));
});

it('renders onboarding for an issued-credential user', function () {
    $user = User::factory()->credentialChangeRequired()->create();

    $this->actingAs($user)
        ->get(route('onboarding.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Onboarding')
            ->has('passwordRules'));
});

it('redirects users without an issued credential away from onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.edit'))
        ->assertRedirect(route('dashboard'));
});

it('confines issued-credential users to onboarding and logout', function () {
    $user = User::factory()->credentialChangeRequired()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.edit'));

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

it('requires confirmation and rejects reuse of the one-time password', function () {
    $user = User::factory()->credentialChangeRequired()->create();

    $this->actingAs($user)
        ->from(route('onboarding.edit'))
        ->put(route('onboarding.update'), [
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->from(route('onboarding.edit'))
        ->put(route('onboarding.update'), [
            'password' => 'new-private-password',
            'password_confirmation' => 'different-password',
        ])
        ->assertSessionHasErrors('password');

    expect($user->refresh()->requiresCredentialChange())->toBeTrue();
});

it('atomically completes onboarding and revokes other sessions and remember tokens', function () {
    Log::spy();
    $user = User::factory()->credentialChangeRequired()->create();
    $rememberToken = $user->remember_token;
    DB::table('sessions')->insert([
        'id' => 'other-session',
        'user_id' => $user->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)
        ->put(route('onboarding.update'), [
            'password' => 'new-private-password',
            'password_confirmation' => 'new-private-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect(Hash::check('new-private-password', $user->password))->toBeTrue()
        ->and($user->requiresCredentialChange())->toBeFalse()
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse();

    $this->get(route('dashboard'))->assertOk();
    Log::shouldHaveReceived('notice')->once()->with('security.audit', [
        'event' => 'initial_credential_replaced',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
    ]);
});

it('rate limits onboarding by user and ip address', function () {
    $user = User::factory()->credentialChangeRequired()->create();
    $key = md5('credentials'.$user->getAuthIdentifier().'|127.0.0.1');
    RateLimiter::increment($key, amount: 6);

    $this->actingAs($user)
        ->put(route('onboarding.update'), [
            'password' => 'new-private-password',
            'password_confirmation' => 'new-private-password',
        ])
        ->assertTooManyRequests();

    expect($user->refresh()->requiresCredentialChange())->toBeTrue();
});
