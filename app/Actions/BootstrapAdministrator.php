<?php

namespace App\Actions;

use App\Models\User;
use App\Support\OneTimePasswordGenerator;
use App\Support\SecurityAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BootstrapAdministrator
{
    public function __construct(private readonly OneTimePasswordGenerator $passwordGenerator) {}

    /**
     * @return array{user: User, password: string}|null
     */
    public function handle(string $name, string $email): ?array
    {
        $result = null;

        Cache::lock('users:administrator-bootstrap', 30)->block(5, function () use ($name, $email, &$result): void {
            $result = $this->createIfEmpty($name, $email);
        });

        if ($result !== null) {
            SecurityAudit::administratorBootstrapped($result['user']);
        }

        return $result;
    }

    /**
     * @return array{user: User, password: string}|null
     */
    private function createIfEmpty(string $name, string $email): ?array
    {
        return DB::transaction(function () use ($name, $email): ?array {
            if (User::query()->exists()) {
                return null;
            }

            $password = $this->passwordGenerator->generate();
            $issuedAt = now();
            $user = new User;
            $user->name = $name;
            $user->email = $email;
            $user->password = $password;
            $user->email_verified_at = $issuedAt;
            $user->is_administrator = true;
            $user->credentials_change_required_at = $issuedAt;
            $user->initial_credential_issued_at = $issuedAt;
            $user->save();

            return ['user' => $user, 'password' => $password];
        });
    }
}
