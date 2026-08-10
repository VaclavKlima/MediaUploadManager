<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Livewire\Pulse\FailedJobs;
use App\Livewire\Pulse\MediaDiskHealth;
use App\Livewire\Pulse\MoviePipelineHealth;
use App\Livewire\Pulse\ProcessHealth;
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
use App\Support\Operations\ProcessHeartbeat;
use App\Support\Pulse\DatabaseStorage;
use App\Support\SecurityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Pulse\Contracts\Storage as PulseStorage;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(PulseStorage::class, DatabaseStorage::class);
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
        $this->configureOperations();
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

        DevCommands::artisan('queue:listen --sleep=1 --tries=0 --timeout=210', 'queue')->green();
        DevCommands::artisan('schedule:work', 'scheduler')->blue();
        DevCommands::artisan('pulse:check', 'pulse')->purple();
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

    private function configureOperations(): void
    {
        Gate::define('viewPulse', fn (User $user): bool => $user->isAdministrator());

        Livewire::component('pulse.failed-jobs', FailedJobs::class);
        Livewire::component('pulse.process-health', ProcessHealth::class);
        Livewire::component('pulse.movie-pipeline-health', MoviePipelineHealth::class);
        Livewire::component('pulse.media-disk-health', MediaDiskHealth::class);

        $this->app->make(Dispatcher::class)->listen(
            Looping::class,
            function (): void {
                ProcessHeartbeat::recordQueueWorker();
            },
        );

        $this->app->make(Dispatcher::class)->listen(
            DiagnosingHealth::class,
            function (): void {
                DB::connection()->getPdo();

                $cacheKey = 'operations:health:'.Str::random(24);

                try {
                    Cache::put($cacheKey, 'healthy', 30);

                    if (Cache::get($cacheKey) !== 'healthy') {
                        throw new \RuntimeException('The application cache is unavailable.');
                    }
                } finally {
                    Cache::forget($cacheKey);
                }
            },
        );
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
