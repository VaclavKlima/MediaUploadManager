<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Str;

class ProvisionLocalAgent
{
    public const string EMAIL = 'local-ai-agent@localhost.invalid';

    public const string NAME = 'Local AI Agent';

    public function handle(): User
    {
        $user = User::query()->firstOrNew(['email' => self::EMAIL]);

        if (! $user->exists) {
            $user->password = Str::password(64);
        }

        $user->name = self::NAME;
        $user->email_verified_at ??= now();
        $user->is_administrator = true;
        $user->credentials_change_required_at = null;
        $user->disabled_at = null;
        $user->initial_credential_issued_at = null;
        $user->save();

        return $user;
    }
}
