<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is an administrator.
     */
    public function administrator(): static
    {
        return $this->state(fn (): array => [
            'is_administrator' => true,
        ]);
    }

    /**
     * Indicate that the user must replace their issued credential.
     */
    public function credentialChangeRequired(): static
    {
        return $this->state(fn (): array => [
            'credentials_change_required_at' => now(),
            'initial_credential_issued_at' => now(),
        ]);
    }

    /**
     * Indicate that the user is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'disabled_at' => now(),
        ]);
    }
}
