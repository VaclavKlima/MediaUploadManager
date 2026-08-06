<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property bool $is_administrator
 * @property CarbonInterface|null $credentials_change_required_at
 * @property CarbonInterface|null $disabled_at
 * @property CarbonInterface|null $initial_credential_issued_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_administrator' => false,
    ];

    /**
     * Determine whether the user may administer the application.
     */
    public function isAdministrator(): bool
    {
        return $this->is_administrator;
    }

    /**
     * Determine whether the user must replace an issued credential.
     */
    public function requiresCredentialChange(): bool
    {
        return $this->credentials_change_required_at !== null;
    }

    /**
     * Determine whether the user account is disabled.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_administrator' => 'boolean',
            'credentials_change_required_at' => 'datetime',
            'disabled_at' => 'datetime',
            'initial_credential_issued_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
