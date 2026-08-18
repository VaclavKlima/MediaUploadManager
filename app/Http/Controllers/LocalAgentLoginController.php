<?php

namespace App\Http\Controllers;

use App\Actions\ProvisionLocalAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;

class LocalAgentLoginController extends Controller
{
    /** @var list<string> */
    private const array LOOPBACK_RANGES = ['127.0.0.0/8', '::1'];

    public function __invoke(Request $request, ProvisionLocalAgent $provisionLocalAgent): RedirectResponse
    {
        abort_unless($this->isAvailable($request), 404);

        Auth::guard('web')->login($provisionLocalAgent->handle());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function isAvailable(Request $request): bool
    {
        $ipAddress = $request->ip();

        return app()->environment('local')
            && config('auth.local_agent_login.enabled') === true
            && is_string($ipAddress)
            && IpUtils::checkIp($ipAddress, self::LOOPBACK_RANGES);
    }
}
