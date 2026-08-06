<?php

use App\Models\User;
use App\Support\OneTimePasswordGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\mock;

it('guides an operator through administrator bootstrap', function () {
    $this->artisan('admin:bootstrap')
        ->expectsQuestion('Administrator name', '  Ada Lovelace  ')
        ->expectsQuestion('Administrator email', '  ADA@EXAMPLE.COM  ')
        ->expectsConfirmation('Create this administrator?', 'yes')
        ->assertSuccessful();

    $administrator = User::query()->sole();

    expect($administrator)
        ->name->toBe('Ada Lovelace')
        ->email->toBe('ada@example.com')
        ->isAdministrator()->toBeTrue()
        ->requiresCredentialChange()->toBeTrue()
        ->initial_credential_issued_at->not->toBeNull();
});

it('cancels interactive bootstrap without changing the database', function () {
    $this->artisan('admin:bootstrap')
        ->expectsQuestion('Administrator name', 'Ada Lovelace')
        ->expectsQuestion('Administrator email', 'ada@example.com')
        ->expectsConfirmation('Create this administrator?', 'no')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
});

it('validates interactive identity fields inline', function () {
    $this->artisan('admin:bootstrap')
        ->expectsQuestion('Administrator name', 'Ada Lovelace')
        ->expectsQuestion('Administrator email', 'not-an-email')
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse();
});

it('requires valid options for unattended bootstrap', function (array $arguments) {
    $this->artisan('admin:bootstrap', [...$arguments, '--no-interaction' => true])
        ->assertExitCode(2);

    expect(User::query()->exists())->toBeFalse();
})->with([
    'missing name' => [['--email' => 'ada@example.com']],
    'missing email' => [['--name' => 'Ada Lovelace']],
    'invalid email' => [['--name' => 'Ada Lovelace', '--email' => 'not-an-email']],
]);

it('creates a normalized administrator during unattended bootstrap', function () {
    $this->artisan('admin:bootstrap', [
        '--name' => '  Ada Lovelace  ',
        '--email' => '  ADA@EXAMPLE.COM  ',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole())
        ->name->toBe('Ada Lovelace')
        ->email->toBe('ada@example.com');
});

it('prints a usable one-time password exactly once and never audits it', function () {
    Log::spy();
    $password = 'A9!bootstrap-password-1234567890';
    mock(OneTimePasswordGenerator::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturn($password);

    Artisan::call('admin:bootstrap', [
        '--name' => 'Ada Lovelace',
        '--email' => 'ada@example.com',
        '--no-interaction' => true,
    ]);

    $output = Artisan::output();
    expect($password)->toHaveLength(32)
        ->and(substr_count($output, $password))->toBe(1)
        ->and(Hash::check($password, User::query()->sole()->password))->toBeTrue();

    Log::shouldHaveReceived('notice')->once()->withArgs(function (string $message, array $context) use ($password): bool {
        return $message === 'security.audit'
            && $context['event'] === 'administrator_bootstrapped'
            && ! str_contains(serialize($context), $password);
    });
});

it('is an immediate no-op when any user already exists', function () {
    $user = User::factory()->create();
    $passwordHash = $user->password;

    $this->artisan('admin:bootstrap', [
        '--name' => 'Ada Lovelace',
        '--email' => 'ada@example.com',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->password)->toBe($passwordHash);
});
