<?php

namespace App\Providers;

use App\Models\User;
use App\Support\SecurityAudit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureViews();
        $this->configureAuthentication();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
        ]));
    }

    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = $request->string(Fortify::username())->trim()->lower()->value();
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User) {
                return null;
            }

            if ($user->isDisabled()) {
                SecurityAudit::disabledAuthenticationRejected($user, $request->ip(), 'login');

                return null;
            }

            return Hash::check($request->string('password')->value(), $user->password)
                ? $user
                : null;
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                $request->string(Fortify::username())->lower()->value().'|'.($request->ip() ?? 'unknown'),
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('credentials', function (Request $request) {
            $user = $request->user();
            $userIdentifier = $user instanceof User ? (string) $user->id : 'guest';

            return Limit::perMinute(6)->by($userIdentifier.'|'.($request->ip() ?? 'unknown'));
        });
    }
}
