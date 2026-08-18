<?php

namespace App\Support\Pulse;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Pulse\Recorders\Servers;
use Throwable;

final readonly class IncidentContext
{
    public const PULSE_TYPE = 'exception-context';

    public const SCHEMA = 'media-upload-manager.error-context/v1';

    private const MAX_PAYLOAD_BYTES = 32_768;

    private const MAX_MESSAGE_LENGTH = 2_000;

    private const MAX_TRACE_FRAMES = 20;

    private const MAX_PREVIOUS_EXCEPTIONS = 2;

    public function __construct(
        private Application $application,
        private Repository $config,
    ) {}

    /** @return array<string, mixed> */
    public function fromException(Throwable $exception): array
    {
        $exceptionDetails = $this->exceptionDetails($exception, self::MAX_TRACE_FRAMES);
        $location = is_string($exceptionDetails['location'] ?? null)
            ? $exceptionDetails['location']
            : null;
        $fingerprint = hash('sha256', $exception::class.'|'.($location ?? 'unknown'));
        $previous = [];
        $previousException = $exception->getPrevious();

        while ($previousException instanceof Throwable && count($previous) < self::MAX_PREVIOUS_EXCEPTIONS) {
            $previous[] = $this->exceptionDetails($previousException, 5);
            $previousException = $previousException->getPrevious();
        }

        $exceptionDetails['previous'] = $previous;

        return $this->limitPayload([
            ...$this->baseContext('exception', (string) Str::ulid(), $fingerprint, now()),
            'exception' => $exceptionDetails,
            'request' => $this->requestContext(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function fromFailedJob(object $failedJob): ?array
    {
        $uuid = $failedJob->id ?? null;

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $failedAt = $failedJob->failed_at ?? null;
        $occurredAt = $failedAt instanceof CarbonInterface
            ? $failedAt
            : (is_string($failedAt) ? $this->parseDate($failedAt) : now());
        $fingerprint = hash('sha256', 'failed-job|'.$uuid);
        $payload = is_string($failedJob->payload ?? null)
            ? json_decode($failedJob->payload, true)
            : null;
        $jobClass = is_array($payload) ? data_get($payload, 'data.commandName') : null;

        if (! is_string($jobClass) || ! Str::startsWith($jobClass, 'App\\Jobs\\')) {
            $jobClass = null;
        }

        return $this->limitPayload([
            ...$this->baseContext('failed_job', $uuid, $fingerprint, $occurredAt),
            'exception' => $this->failedJobException(
                is_string($failedJob->exception ?? null) ? $failedJob->exception : '',
            ),
            'job' => [
                'uuid' => $uuid,
                'class' => $jobClass,
                'connection' => $this->nullableLabel($failedJob->connection ?? null),
                'queue' => $this->nullableLabel($failedJob->queue ?? null),
            ],
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public function toJson(array $context): string
    {
        return json_encode(
            $context,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param  array<string, mixed>  $context */
    public function toMarkdown(array $context): string
    {
        $exception = is_array($context['exception'] ?? null) ? $context['exception'] : [];
        $lines = [
            '# Media Upload Manager error context',
            '',
            '- Schema: `'.$this->markdownValue($context['schema'] ?? null).'`',
            '- Source: `'.$this->markdownValue($context['source'] ?? null).'`',
            '- Incident ID: `'.$this->markdownValue($context['id'] ?? null).'`',
            '- Occurred at: `'.$this->markdownValue($context['occurred_at'] ?? null).'`',
            '- Environment: `'.$this->markdownValue($context['environment'] ?? null).'`',
            '- Release: `'.$this->markdownValue($context['release'] ?? null).'`',
            '- Server: `'.$this->markdownValue($context['server'] ?? null).'`',
            '',
            '## Exception',
            '',
            '- Class: `'.$this->markdownValue($exception['class'] ?? null).'`',
            '- Message: '.$this->markdownValue($exception['message'] ?? null),
            '- Location: `'.$this->markdownValue($exception['location'] ?? null).'`',
        ];

        $request = is_array($context['request'] ?? null) ? $context['request'] : null;

        if ($request !== null) {
            $lines = [
                ...$lines,
                '',
                '## Request',
                '',
                '- Method: `'.$this->markdownValue($request['method'] ?? null).'`',
                '- Route name: `'.$this->markdownValue($request['route_name'] ?? null).'`',
                '- Route template: `'.$this->markdownValue($request['route_uri'] ?? null).'`',
                '- User ID: `'.$this->markdownValue($request['user_id'] ?? null).'`',
            ];
        }

        $job = is_array($context['job'] ?? null) ? $context['job'] : null;

        if ($job !== null) {
            $lines = [
                ...$lines,
                '',
                '## Failed job',
                '',
                '- UUID: `'.$this->markdownValue($job['uuid'] ?? null).'`',
                '- Class: `'.$this->markdownValue($job['class'] ?? null).'`',
                '- Connection: `'.$this->markdownValue($job['connection'] ?? null).'`',
                '- Queue: `'.$this->markdownValue($job['queue'] ?? null).'`',
            ];
        }

        $trace = is_array($exception['trace'] ?? null) ? $exception['trace'] : [];

        if ($trace !== []) {
            $lines[] = '';
            $lines[] = '## Application trace';
            $lines[] = '';

            foreach ($trace as $frame) {
                if (! is_array($frame)) {
                    continue;
                }

                $location = $this->markdownValue($frame['file'] ?? null)
                    .(is_int($frame['line'] ?? null) ? ':'.$frame['line'] : '');
                $call = $this->markdownValue($frame['call'] ?? null);
                $lines[] = '- `'.$location.'`'.($call === 'n/a' ? '' : ' — `'.$call.'`');
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<string, mixed>|null */
    public function decode(string $value): ?array
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return null;
        }

        $context = [];

        foreach ($decoded as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $context[$key] = $item;
        }

        if (($context['schema'] ?? null) !== self::SCHEMA
            || ! in_array($context['source'] ?? null, ['exception', 'failed_job'], true)
            || ! is_string($context['fingerprint'] ?? null)) {
            return null;
        }

        return $context;
    }

    /**
     * @return array{
     *     schema: string,
     *     source: string,
     *     id: string,
     *     fingerprint: string,
     *     occurred_at: string,
     *     environment: string,
     *     release: string,
     *     server: string,
     *     runtime: array{php: string, laravel: string}
     * }
     */
    private function baseContext(
        string $source,
        string $id,
        string $fingerprint,
        CarbonInterface $occurredAt,
    ): array {
        $serverName = $this->config->get('pulse.recorders.'.Servers::class.'.server_name', 'unknown');

        return [
            'schema' => self::SCHEMA,
            'source' => $source,
            'id' => $id,
            'fingerprint' => $fingerprint,
            'occurred_at' => $occurredAt->toImmutable()->utc()->toIso8601String(),
            'environment' => $this->nullableLabel($this->config->get('app.env')) ?? 'unknown',
            'release' => $this->nullableLabel($this->config->get('app.release')) ?? 'development',
            'server' => $this->nullableLabel($serverName) ?? 'unknown',
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => $this->application->version(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function exceptionDetails(Throwable $exception, int $traceLimit): array
    {
        return [
            'class' => $exception::class,
            'message' => $this->sanitizeText($exception->getMessage(), self::MAX_MESSAGE_LENGTH),
            'location' => $this->exceptionLocation($exception),
            'trace' => $this->applicationTrace($exception, $traceLimit),
        ];
    }

    /** @return array<string, mixed> */
    private function failedJobException(string $exception): array
    {
        $lines = preg_split('/\R/u', $exception) ?: [];
        $headline = array_shift($lines) ?? '';
        $class = 'Unknown';
        $message = $headline;
        $location = null;

        if (preg_match('/^(?<class>[A-Za-z_][A-Za-z0-9_\\\\]*): (?<message>.*?)(?: in (?<file>.+):(?<line>\d+))?$/u', $headline, $matches) === 1) {
            $class = $matches['class'];
            $message = $matches['message'];
            $relativePath = $this->relativeApplicationPath($matches['file'] ?? '');

            if ($relativePath !== null) {
                $location = $relativePath.(isset($matches['line']) ? ':'.$matches['line'] : '');
            }
        }

        $trace = [];

        foreach ($lines as $line) {
            if (count($trace) >= self::MAX_TRACE_FRAMES || ! Str::contains($line, base_path())) {
                continue;
            }

            if (preg_match('/#\d+\s+(?<file>.+?)\((?<line>\d+)\):\s*(?<call>.*)$/u', $line, $matches) !== 1) {
                continue;
            }

            $relativePath = $this->relativeApplicationPath($matches['file']);

            if ($relativePath === null) {
                continue;
            }

            $trace[] = [
                'file' => $relativePath,
                'line' => (int) $matches['line'],
                'call' => $this->sanitizeText($matches['call'], 240),
            ];
        }

        if ($location === null && isset($trace[0]['file'])) {
            $location = $trace[0]['file'].':'.$trace[0]['line'];
        }

        return [
            'class' => $class,
            'message' => $this->sanitizeText($message, self::MAX_MESSAGE_LENGTH),
            'location' => $location,
            'trace' => $trace,
            'previous' => [],
        ];
    }

    private function exceptionLocation(Throwable $exception): ?string
    {
        $relativePath = $this->relativeApplicationPath($exception->getFile());

        if ($relativePath !== null) {
            return $relativePath.':'.$exception->getLine();
        }

        foreach ($exception->getTrace() as $frame) {
            $relativePath = $this->relativeApplicationPath(
                is_string($frame['file'] ?? null) ? $frame['file'] : '',
            );

            if ($relativePath !== null) {
                return $relativePath.(is_int($frame['line'] ?? null) ? ':'.$frame['line'] : '');
            }
        }

        return null;
    }

    /** @return list<array{file: string, line: int|null, call: string|null}> */
    private function applicationTrace(Throwable $exception, int $limit): array
    {
        $trace = [];

        foreach ($exception->getTrace() as $frame) {
            $relativePath = $this->relativeApplicationPath(
                is_string($frame['file'] ?? null) ? $frame['file'] : '',
            );

            if ($relativePath === null) {
                continue;
            }

            $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';
            $type = is_string($frame['type'] ?? null) ? $frame['type'] : '';
            $function = $frame['function'];
            $call = Str::limit($class.$type.$function, 240, '');
            $trace[] = [
                'file' => $relativePath,
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
                'call' => $call === '' ? null : $call,
            ];

            if (count($trace) >= $limit) {
                break;
            }
        }

        return $trace;
    }

    /** @return array<string, int|string|null>|null */
    private function requestContext(): ?array
    {
        if ($this->application->runningInConsole() || ! $this->application->bound('request')) {
            return null;
        }

        $request = $this->application->make('request');

        $route = $request->route();
        $userIdentifier = $request->user()?->getAuthIdentifier();

        return [
            'method' => Str::upper($request->method()),
            'route_name' => $route instanceof Route ? $route->getName() : null,
            'route_uri' => $route instanceof Route ? $route->uri() : null,
            'user_id' => is_int($userIdentifier) || is_string($userIdentifier)
                ? $userIdentifier
                : null,
        ];
    }

    private function relativeApplicationPath(string $path): ?string
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! Str::startsWith($path, $basePath)
            || Str::startsWith($path, $basePath.'vendor'.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return Str::limit(
            str_replace(DIRECTORY_SEPARATOR, '/', Str::after($path, $basePath)),
            240,
            '',
        );
    }

    private function sanitizeText(string $value, int $limit): string
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $value = Str::replace($basePath, '', $value);
        $value = preg_replace_callback(
            '~https?://[^\s<>"\']+~iu',
            function (array $matches): string {
                $parts = parse_url($matches[0]);

                if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
                    return '[REDACTED_URL]';
                }

                $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'];

                if (isset($parts['port'])) {
                    $origin .= ':'.$parts['port'];
                }

                return $origin.(isset($parts['path']) && $parts['path'] !== '/' ? '/[REDACTED_PATH]' : '');
            },
            $value,
        ) ?? $value;
        $value = preg_replace(
            '~\bBearer\s+[A-Za-z0-9._\~+/=-]+~iu',
            'Bearer [REDACTED]',
            $value,
        ) ?? $value;
        $value = preg_replace(
            '~\b(?:password|passphrase|secret(?:\s+token)?|access[ _-]?token|refresh[ _-]?token|api[ _-]?key|app[ _-]?key|authorization|signature|hook[ _-]?secret)\b\s*(?::|=|\bis\b)?\s*[^\s,;]+~iu',
            '[REDACTED]',
            $value,
        ) ?? $value;
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[REDACTED_EMAIL]', $value) ?? $value;
        $value = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', '[REDACTED_IP]', $value) ?? $value;
        $value = preg_replace(
            '~(?<![A-Za-z0-9_:\\-])/(?:[^\s/:]+/)+[^\s,:\)\]]+~u',
            '[REDACTED_PATH]',
            $value,
        ) ?? $value;
        $value = preg_replace(
            '~\b[A-Za-z]:\\\\(?:[^\s\\\\]+\\\\)+[^\s,;]+~u',
            '[REDACTED_PATH]',
            $value,
        ) ?? $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return Str::limit(Str::squish($value), $limit, '…');
    }

    private function nullableLabel(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $label = $this->sanitizeText((string) $value, 120);

        return $label === '' ? null : $label;
    }

    private function markdownValue(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return 'n/a';
        }

        $text = Str::replace(['`', "\r", "\n"], ['\'', ' ', ' '], (string) $value);

        return $text === '' ? 'n/a' : $text;
    }

    private function parseDate(string $value): CarbonInterface
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return now();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function limitPayload(array $context): array
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        while (is_string($encoded) && strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $exception = $context['exception'] ?? null;

            if (! is_array($exception)) {
                break;
            }

            $previous = $exception['previous'] ?? [];

            if (is_array($previous) && $previous !== []) {
                array_pop($previous);
                $exception['previous'] = $previous;
            } else {
                $trace = $exception['trace'] ?? [];

                if (is_array($trace) && count($trace) > 5) {
                    array_pop($trace);
                    $exception['trace'] = $trace;
                } else {
                    $message = $exception['message'] ?? '';
                    $exception['message'] = Str::limit(is_string($message) ? $message : '', 500, '…');
                    $exception['trace'] = [];
                    $exception['previous'] = [];

                    $context['exception'] = $exception;

                    return $context;
                }
            }

            $context['exception'] = $exception;
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $context;
    }
}
