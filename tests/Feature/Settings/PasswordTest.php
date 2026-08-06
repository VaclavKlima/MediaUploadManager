<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

it('renders password settings for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Password')
            ->has('passwordRules'));
});

it('updates the current user password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-private-password',
            'password_confirmation' => 'new-private-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.edit'));

    expect(Hash::check('new-private-password', $user->refresh()->password))->toBeTrue();
});

it('requires the current password when changing password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'incorrect-password',
            'password' => 'new-private-password',
            'password_confirmation' => 'new-private-password',
        ])
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('password.edit'));
});

it('requires password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-private-password',
            'password_confirmation' => 'different-password',
        ])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('password.edit'));
});

it('rate limits password changes by user and ip address', function () {
    $user = User::factory()->create();
    $key = md5('credentials'.$user->getAuthIdentifier().'|127.0.0.1');
    RateLimiter::increment($key, amount: 6);

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-private-password',
            'password_confirmation' => 'new-private-password',
        ])
        ->assertTooManyRequests();

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});
