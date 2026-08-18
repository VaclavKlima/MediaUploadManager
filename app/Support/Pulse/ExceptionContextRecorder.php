<?php

namespace App\Support\Pulse;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Pulse\Events\ExceptionReported;
use Laravel\Pulse\Pulse;
use Throwable;

final readonly class ExceptionContextRecorder
{
    public function __construct(
        private Pulse $pulse,
        private Repository $config,
        private IncidentContext $incidentContext,
    ) {}

    public function register(callable $record, Application $application): void
    {
        $this->afterResolving(
            $application,
            ExceptionHandler::class,
            fn (ExceptionHandler $handler) => $handler->reportable(
                fn (Throwable $exception) => $record($exception),
            ),
        );

        $this->afterResolving(
            $application,
            Dispatcher::class,
            fn (Dispatcher $events) => $events->listen(
                fn (ExceptionReported $event) => $record($event->exception),
            ),
        );
    }

    public function record(Throwable $exception): void
    {
        if (! $this->config->get('pulse.recorders.'.self::class.'.enabled', true)
            || $this->shouldIgnore($exception::class)) {
            return;
        }

        $context = $this->incidentContext->fromException($exception);
        $fingerprint = $context['fingerprint'] ?? null;

        if (! is_string($fingerprint)) {
            return;
        }

        $this->pulse->set(
            type: IncidentContext::PULSE_TYPE,
            key: $fingerprint,
            value: $this->incidentContext->toJson($context),
            timestamp: now(),
        );
    }

    private function shouldIgnore(string $exceptionClass): bool
    {
        $patterns = $this->config->get('pulse.recorders.'.self::class.'.ignore', []);

        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $exceptionClass) === 1) {
                return true;
            }
        }

        return false;
    }

    private function afterResolving(Application $application, string $class, Closure $callback): void
    {
        $application->afterResolving($class, $callback);

        if ($application->resolved($class)) {
            $callback($application->make($class));
        }
    }
}
