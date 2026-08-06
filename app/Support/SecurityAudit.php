<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

final class SecurityAudit
{
    public static function administratorBootstrapped(User $user): void
    {
        self::write('administrator_bootstrapped', [
            'user_id' => $user->id,
        ]);
    }

    public static function loginSucceeded(User $user, ?string $ipAddress): void
    {
        self::write('login_succeeded', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
        ]);
    }

    public static function disabledAuthenticationRejected(User $user, ?string $ipAddress, string $source): void
    {
        self::write('disabled_authentication_rejected', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'source' => $source,
        ]);
    }

    public static function administratorRecovered(User $user, bool $wasReEnabled): void
    {
        self::write('administrator_recovered', [
            'user_id' => $user->id,
            'was_re_enabled' => $wasReEnabled,
        ]);
    }

    public static function initialCredentialReplaced(User $user, ?string $ipAddress): void
    {
        self::write('initial_credential_replaced', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @param  array<string, bool|int|string|null>  $context
     */
    private static function write(string $event, array $context): void
    {
        Log::notice('security.audit', [
            'event' => $event,
            ...$context,
        ]);
    }
}
