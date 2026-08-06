<?php

namespace App\Actions;

use App\Models\User;
use App\Support\SecurityAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompleteCredentialChange
{
    public function handle(User $user, string $password, Request $request): void
    {
        $completed = DB::transaction(function () use ($user, $password, $request): bool {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedUser->requiresCredentialChange()) {
                return false;
            }

            $lockedUser->password = $password;
            $lockedUser->credentials_change_required_at = null;
            $lockedUser->remember_token = Str::random(60);
            $lockedUser->save();

            $otherSessions = DB::table('sessions')->where('user_id', $lockedUser->getKey());

            if ($request->hasSession()) {
                $otherSessions->where('id', '!=', $request->session()->getId());
            }

            $otherSessions->delete();

            return true;
        });

        if (! $completed) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        SecurityAudit::initialCredentialReplaced($user, $request->ip());
    }
}
