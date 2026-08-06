<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAccountSecurity
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isDisabled()) {
            SecurityAudit::disabledAuthenticationRejected($user, $request->ip(), 'session');
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return to_route('login');
        }

        if ($user->requiresCredentialChange() && ! $request->routeIs('onboarding.*', 'logout')) {
            return to_route('onboarding.edit');
        }

        return $next($request);
    }
}
