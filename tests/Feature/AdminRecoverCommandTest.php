<?php

use App\Models\User;
use App\Support\OneTimePasswordGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\mock;

it('requires an email for unattended recovery', function () {
    User::factory()->administrator()->create();

    $this->artisan('admin:recover', ['--no-interaction' => true])
        ->assertExitCode(2);
});

it('rejects recovery of a nonadministrator', function () {
    $user = User::factory()->create();
    $passwordHash = $user->password;

    $this->artisan('admin:recover', [
        '--email' => $user->email,
        '--no-interaction' => true,
    ])->assertExitCode(2);

    expect($user->refresh()->password)->toBe($passwordHash);
});

it('recovers an administrator and revokes existing sessions', function () {
    Log::spy();
    $administrator = User::factory()->administrator()->create();
    $password = 'A9!recovery-password-12345678901';
    mock(OneTimePasswordGenerator::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturn($password);
    DB::table('sessions')->insert([
        'id' => 'existing-session',
        'user_id' => $administrator->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    Artisan::call('admin:recover', [
        '--email' => mb_strtoupper($administrator->email),
        '--no-interaction' => true,
    ]);

    $administrator->refresh();

    expect($password)->toHaveLength(32)
        ->and(Hash::check($password, $administrator->password))->toBeTrue()
        ->and($administrator->requiresCredentialChange())->toBeTrue()
        ->and($administrator->remember_token)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $administrator->getKey())->exists())->toBeFalse();

    Log::shouldHaveReceived('notice')->once()->with('security.audit', [
        'event' => 'administrator_recovered',
        'user_id' => $administrator->id,
        'was_re_enabled' => false,
    ]);
});

it('preserves disabled status unless explicitly re-enabled', function () {
    $administrator = User::factory()->administrator()->disabled()->create();

    $this->artisan('admin:recover', [
        '--email' => $administrator->email,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($administrator->refresh()->isDisabled())->toBeTrue();

    $this->artisan('admin:recover', [
        '--email' => $administrator->email,
        '--enable' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($administrator->refresh()->isDisabled())->toBeFalse();
});

it('can cancel interactive recovery without changing the password', function () {
    $administrator = User::factory()->administrator()->create();
    $passwordHash = $administrator->password;

    $this->artisan('admin:recover', ['--email' => $administrator->email])
        ->expectsConfirmation('Issue a new one-time password and revoke existing sessions?', 'no')
        ->assertSuccessful();

    expect($administrator->refresh()->password)->toBe($passwordHash);
});

it('offers a searchable interactive administrator selection', function () {
    $administrator = User::factory()->administrator()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);
    $options = [$administrator->id => 'Ada Lovelace <ada@example.com>'];

    $this->artisan('admin:recover')
        ->expectsSearch('Select an administrator', $administrator->id, 'ada', $options)
        ->expectsConfirmation('Issue a new one-time password and revoke existing sessions?', 'yes')
        ->assertSuccessful();

    expect($administrator->refresh()->requiresCredentialChange())->toBeTrue();
});
