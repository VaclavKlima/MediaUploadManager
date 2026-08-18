<?php

use App\Jobs\ScanMovieLibrary;
use App\Livewire\Pulse\ExceptionContext;
use App\Livewire\Pulse\FailedJobs;
use App\Models\User;
use App\Support\Pulse\ExceptionContextRecorder;
use App\Support\Pulse\IncidentContext;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Servers;
use Laravel\Pulse\Value;
use Livewire\Livewire;

function exceptionContextTestException(string $message): RuntimeException
{
    return new RuntimeException($message);
}

it('builds a useful exception bundle without request secrets or private paths', function () {
    $administrator = User::factory()->administrator()->create();
    $route = new Route('POST', 'movies/{movie}', fn () => null);
    $route->name('movies.update');
    $request = Request::create(
        '/movies/42?token=query-secret',
        'POST',
        ['password' => 'request-secret'],
        ['session' => 'cookie-secret'],
        [],
        ['HTTP_AUTHORIZATION' => 'Bearer header-secret'],
    );
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $administrator);

    $application = Mockery::mock(Application::class);
    $application->shouldReceive('runningInConsole')->andReturnFalse();
    $application->shouldReceive('bound')->with('request')->andReturnTrue();
    $application->shouldReceive('make')->with('request')->andReturn($request);
    $application->shouldReceive('version')->andReturn('13.24.0');

    $configuration = new Repository([
        'app' => [
            'env' => 'production',
            'release' => 'v0.1.0-beta.25',
        ],
        'pulse' => [
            'recorders' => [
                Servers::class => ['server_name' => 'media-home-server'],
            ],
        ],
    ]);
    $incidentContext = new IncidentContext($application, $configuration);
    $context = $incidentContext->fromException(exceptionContextTestException(
        'Secret token abc123 for person@example.com from 192.168.1.8 at /mnt/private/movies/Example.mkv via https://api.example.com/private?token=url-secret',
    ));
    $json = $incidentContext->toJson($context);
    $markdown = $incidentContext->toMarkdown($context);

    expect($context)
        ->toMatchArray([
            'schema' => IncidentContext::SCHEMA,
            'source' => 'exception',
            'environment' => 'production',
            'release' => 'v0.1.0-beta.25',
            'server' => 'media-home-server',
        ])
        ->and($context['request'])->toMatchArray([
            'method' => 'POST',
            'route_name' => 'movies.update',
            'route_uri' => 'movies/{movie}',
            'user_id' => $administrator->id,
        ])
        ->and($context['exception']['location'])->toStartWith('tests/Feature/IncidentContextTest.php:')
        ->and($json)->toContain('[REDACTED]', '[REDACTED_EMAIL]', '[REDACTED_IP]', '[REDACTED_PATH]')
        ->and($json)->not->toContain(
            'abc123',
            'person@example.com',
            '192.168.1.8',
            '/mnt/private',
            'query-secret',
            'request-secret',
            'cookie-secret',
            'header-secret',
            'url-secret',
        )
        ->and($markdown)->toContain('# Media Upload Manager error context', 'movies.update')
        ->and(strlen($json))->toBeLessThanOrEqual(32_768);
});

it('records only the latest sanitized sample for an exception signature', function () {
    config()->set('pulse.recorders.'.ExceptionContextRecorder::class, [
        'enabled' => true,
        'ignore' => ['/^'.preg_quote(ValidationException::class, '/').'$/'],
    ]);
    config()->set('app.release', 'v0.1.0-beta.25');

    $pulse = app(Pulse::class);
    $pulse->startRecording()->flush();
    $recorder = app(ExceptionContextRecorder::class);

    $recorder->record(exceptionContextTestException('First message'));
    $pulse->ingest();
    $recorder->record(exceptionContextTestException('Latest message'));
    $pulse->ingest();
    $recorder->record(ValidationException::withMessages(['title' => 'Expected validation failure']));
    $pulse->ingest();

    config()->set('pulse.recorders.'.ExceptionContextRecorder::class.'.enabled', false);
    $recorder->record(new LogicException('Disabled recorder message'));
    $pulse->ingest();

    $values = DB::table('pulse_values')
        ->where('type', IncidentContext::PULSE_TYPE)
        ->get();

    expect($values)->toHaveCount(1);

    $context = app(IncidentContext::class)->decode($values->first()->value);

    expect($context)->not->toBeNull()
        ->and($context['exception']['message'])->toBe('Latest message')
        ->and($context['release'])->toBe('v0.1.0-beta.25');
});

it('registers with an exception handler that Laravel has already resolved', function () {
    config()->set('pulse.recorders.'.ExceptionContextRecorder::class, [
        'enabled' => true,
        'ignore' => [],
    ]);

    $pulse = app(Pulse::class);
    $pulse->startRecording()->flush();
    $handler = app(LaravelExceptionHandler::class);
    $recorder = app(ExceptionContextRecorder::class);
    $recorder->register(fn (Throwable $exception) => $recorder->record($exception), app());

    Log::spy();
    $handler->report(exceptionContextTestException('Registered recorder message'));
    $pulse->ingest();

    expect(DB::table('pulse_values')
        ->where('type', IncidentContext::PULSE_TYPE)
        ->count())->toBe(1);
});

it('creates a sanitized failed-job bundle without unserializing its command payload', function () {
    config()->set('app.release', 'v0.1.0-beta.25');
    $uuid = (string) Str::uuid();
    $failedJob = (object) [
        'id' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'data' => [
                'commandName' => ScanMovieLibrary::class,
                'command' => 'SERIALIZED-PRIVATE-PAYLOAD',
            ],
        ], JSON_THROW_ON_ERROR),
        'exception' => sprintf(
            "RuntimeException: Secret token abc123 at /mnt/private/movies/Example.mkv in %s/app/Jobs/ScanMovieLibrary.php:42\n#0 %s/app/Actions/RetryFailedJob.php(10): App\\Jobs\\ScanMovieLibrary->handle()\n#1 %s/vendor/laravel/framework/src/Worker.php(1): run()",
            base_path(),
            base_path(),
            base_path(),
        ),
        'failed_at' => now(),
    ];

    $incidentContext = app(IncidentContext::class);
    $context = $incidentContext->fromFailedJob($failedJob);
    $json = $incidentContext->toJson($context);

    expect($context)->not->toBeNull()
        ->and($context['source'])->toBe('failed_job')
        ->and($context['job'])->toMatchArray([
            'uuid' => $uuid,
            'class' => ScanMovieLibrary::class,
            'connection' => 'database',
            'queue' => 'default',
        ])
        ->and($context['exception']['location'])->toBe('app/Jobs/ScanMovieLibrary.php:42')
        ->and($json)->not->toContain('abc123', '/mnt/private', 'SERIALIZED-PRIVATE-PAYLOAD')
        ->and($incidentContext->toMarkdown($context))->toContain(ScanMovieLibrary::class, $uuid);
});

it('renders administrator-only exception and failed-job detail controls', function () {
    $administrator = User::factory()->administrator()->create();
    $incidentContext = app(IncidentContext::class);
    $context = $incidentContext->fromException(exceptionContextTestException('A visible diagnostic message'));

    app(Storage::class)->store(collect([
        new Value(
            now()->getTimestamp(),
            IncidentContext::PULSE_TYPE,
            $context['fingerprint'],
            $incidentContext->toJson($context),
        ),
        new Value(now()->getTimestamp(), IncidentContext::PULSE_TYPE, 'malformed', 'not-json'),
    ]));

    Livewire::actingAs($administrator)
        ->test(ExceptionContext::class, ['lazy' => false])
        ->assertSee('A visible diagnostic message')
        ->assertDontSee('not-json')
        ->call('select', $context['fingerprint'])
        ->assertSet('selectedFingerprint', $context['fingerprint'])
        ->assertSee('Copy Markdown')
        ->assertSee('Copy JSON');

    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['commandName' => ScanMovieLibrary::class]], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: Secret token abc123 in '.base_path().'/app/Jobs/ScanMovieLibrary.php:42',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($administrator)
        ->test(FailedJobs::class, ['lazy' => false])
        ->call('showDetails', $uuid)
        ->assertSet('selectedDetailsUuid', $uuid)
        ->assertSee('Copy Markdown')
        ->assertSee('Copy JSON')
        ->assertDontSee('abc123');

});
