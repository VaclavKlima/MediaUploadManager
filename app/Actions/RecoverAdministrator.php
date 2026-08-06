<?php

namespace App\Actions;

use App\Models\User;
use App\Support\OneTimePasswordGenerator;
use App\Support\SecurityAudit;
use Illuminate\Support\Facades\DB;

class RecoverAdministrator
{
    public function __construct(private readonly OneTimePasswordGenerator $passwordGenerator) {}

    /**
     * @return array{user: User, password: string}
     */
    public function handle(User $administrator, bool $enable): array
    {
        $wasReEnabled = $enable && $administrator->isDisabled();

        $result = DB::transaction(function () use ($administrator, $enable): array {
            $lockedAdministrator = User::query()
                ->whereKey($administrator->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedAdministrator->isAdministrator(), 403);

            $password = $this->passwordGenerator->generate();
            $issuedAt = now();
            $lockedAdministrator->password = $password;
            $lockedAdministrator->credentials_change_required_at = $issuedAt;
            $lockedAdministrator->initial_credential_issued_at = $issuedAt;
            $lockedAdministrator->remember_token = null;

            if ($enable) {
                $lockedAdministrator->disabled_at = null;
            }

            $lockedAdministrator->save();

            DB::table('sessions')
                ->where('user_id', $lockedAdministrator->getKey())
                ->delete();

            return ['user' => $lockedAdministrator, 'password' => $password];
        });

        SecurityAudit::administratorRecovered($result['user'], $wasReEnabled);

        return $result;
    }
}
