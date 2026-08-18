<?php

use App\Actions\ProvisionLocalAgent;
use App\Models\User;

beforeEach(function () {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('auth.local_agent_login.enabled', true);
});

it('authenticates a dedicated administrator from loopback', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get(route('local.agent_login'))
        ->assertRedirect(route('dashboard', absolute: false));

    $agent = User::query()->where('email', ProvisionLocalAgent::EMAIL)->sole();

    $this->assertAuthenticatedAs($agent);
    expect($agent)
        ->name->toBe(ProvisionLocalAgent::NAME)
        ->email_verified_at->not->toBeNull()
        ->is_administrator->toBeTrue()
        ->credentials_change_required_at->toBeNull()
        ->disabled_at->toBeNull()
        ->initial_credential_issued_at->toBeNull();
});

it('reuses and repairs the dedicated account', function () {
    $agent = User::factory()->disabled()->credentialChangeRequired()->create([
        'name' => 'Repurposed Account',
        'email' => ProvisionLocalAgent::EMAIL,
        'email_verified_at' => null,
        'is_administrator' => false,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '::1'])
        ->get(route('local.agent_login'))
        ->assertRedirect(route('dashboard', absolute: false));

    $agent->refresh();

    $this->assertAuthenticatedAs($agent);
    expect(User::query()->where('email', ProvisionLocalAgent::EMAIL)->count())->toBe(1)
        ->and($agent)
        ->name->toBe(ProvisionLocalAgent::NAME)
        ->email_verified_at->not->toBeNull()
        ->is_administrator->toBeTrue()
        ->credentials_change_required_at->toBeNull()
        ->disabled_at->toBeNull()
        ->initial_credential_issued_at->toBeNull();
});

it('redirects to the originally requested protected page', function () {
    $this->get(route('disks.index'))
        ->assertRedirect(route('login'));

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get(route('local.agent_login'))
        ->assertRedirect(route('disks.index'));
});

it('is unavailable when the feature flag is disabled', function () {
    config()->set('auth.local_agent_login.enabled', false);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get(route('local.agent_login'))
        ->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('is unavailable outside the local environment', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get(route('local.agent_login'))
        ->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('is unavailable to non-loopback clients', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->get(route('local.agent_login'))
        ->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});
