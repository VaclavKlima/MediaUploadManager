<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders profile settings for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->missing('mustVerifyEmail'));
});

it('updates profile information', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Media Administrator',
            'email' => 'administrator@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh())
        ->name->toBe('Media Administrator')
        ->email->toBe('administrator@example.com');
});

it('validates profile updates', function (array $payload, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), $payload)
        ->assertSessionHasErrors($field)
        ->assertRedirect(route('profile.edit'));
})->with([
    'name is required' => [['name' => '', 'email' => 'valid@example.com'], 'name'],
    'email must be valid' => [['name' => 'Valid Name', 'email' => 'not-an-email'], 'email'],
]);
