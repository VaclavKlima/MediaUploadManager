<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\Contracts\MountInfoSource;
use App\Support\Media\Contracts\MountPointChecker;
use App\Support\Media\Contracts\OperatingSystem;
use App\Support\Media\LinuxMountInspector;
use App\Support\Media\NativeMediaFilesystem;
use App\Support\Media\NativeMountInfoSource;
use App\Support\Media\NativeOperatingSystem;
use App\Support\Media\UploadConfiguration;
use App\Support\SecurityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(MediaFilesystem::class, NativeMediaFilesystem::class);
        $this->app->singleton(MountInfoSource::class, NativeMountInfoSource::class);
        $this->app->singleton(OperatingSystem::class, NativeOperatingSystem::class);
        $this->app->singleton(MountPointChecker::class, LinuxMountInspector::class);
        $this->app->singleton(
            ConfiguredDiskRegistry::class,
            function (Application $application): ConfiguredDiskRegistry {
                $configuration = config('media');

                return new ConfiguredDiskRegistry(
                    is_array($configuration) ? $configuration : [],
                    $application->make(MediaFilesystem::class),
                    $application->isProduction(),
                );
            },
        );
        $this->app->singleton(
            UploadConfiguration::class,
            function (Application $application): UploadConfiguration {
                $configuration = config('upload');

                return new UploadConfiguration(
                    is_array($configuration) ? $configuration : [],
                    $application->isProduction(),
                );
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
        $this->configureSecurityAuditing();
        $this->configureDevelopmentProcesses();

        if ($this->app->isProduction()) {
            $this->app->make(ConfiguredDiskRegistry::class)->all();
            $this->app->make(UploadConfiguration::class);
        }
    }

    private function configureDevelopmentProcesses(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->environment('local')) {
            return;
        }

        DevCommands::artisan('queue:work --sleep=1 --tries=0 --timeout=210', 'queue')->green();
        DevCommands::artisan('upload:dev --run-only', 'tusd')->orange();
        DevCommands::except('server');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureSecurityAuditing(): void
    {
        $this->app->make(Dispatcher::class)->listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                SecurityAudit::loginSucceeded($event->user, request()->ip());
            }
        });
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('tmdb', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();
            $key = is_int($identifier) || is_string($identifier) ? (string) $identifier : $request->ip();

            return Limit::perMinute(30)
                ->by($key)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'error' => 'movie_lookup_throttled',
                    'message' => 'Too many movie lookup requests. Please try again shortly.',
                ], 429, $headers));
        });
    }
}
