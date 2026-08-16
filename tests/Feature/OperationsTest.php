<?php

use App\Actions\RetryFailedJob;
use App\Enums\UploadStatus;
use App\Jobs\ScanMediaLibrary;
use App\Jobs\ScanMovieLibrary;
use App\Livewire\Pulse\FailedJobs;
use App\Livewire\Pulse\MediaDiskHealth;
use App\Livewire\Pulse\MoviePipelineHealth;
use App\Livewire\Pulse\ProcessHealth;
use App\Models\LibraryScan;
use App\Models\Upload;
use App\Models\User;
use App\Support\Operations\ProcessHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Recorders\Exceptions;
use Laravel\Pulse\Value;
use Livewire\Livewire;

it('limits the Pulse operations dashboard to administrators', function () {
    $user = User::factory()->create(['is_administrator' => false]);
    $administrator = User::factory()->create(['is_administrator' => true]);

    $this->get('/pulse')->assertForbidden();
    $this->actingAs($user)->get('/pulse')->assertForbidden();
    $this->actingAs($user)->get(route('operations'))->assertForbidden();

    $this->actingAs($administrator)
        ->get('/pulse')
        ->assertSuccessful()
        ->assertSee('pulse.process-health')
        ->assertSee('pulse.movie-pipeline-health')
        ->assertSee('pulse.media-disk-health')
        ->assertSee('pulse.failed-jobs');

    $this->actingAs($administrator)
        ->get(route('operations'))
        ->assertRedirect(route('pulse'));
});

it('shows only sanitized failed-job data and safely retries an allowlisted job', function () {
    $administrator = User::factory()->create(['is_administrator' => true]);
    $uuid = (string) Str::uuid();
    app('queue')->connection('database')->push(new ScanMediaLibrary(123));
    $payload = DB::table('jobs')->value('payload');
    DB::table('jobs')->delete();

    expect($payload)->toBeString();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => "Secret token abc123 at /mnt/private/movies/Example.mkv\nStack trace",
        'failed_at' => now(),
    ]);

    $retry = app(RetryFailedJob::class);
    $summary = $retry->summaries()[0];

    expect($summary)
        ->toMatchArray([
            'id' => $uuid,
            'name' => 'Scan media library',
            'summary' => 'A retryable media-management task failed.',
            'retryable' => true,
        ])
        ->and(json_encode($summary))->not->toContain('abc123', '/mnt/private', 'Stack trace');

    Log::spy();
    $this->actingAs($administrator);

    Livewire::test(FailedJobs::class)
        ->call('requestRetry', $uuid)
        ->assertSet('pendingRetryUuid', $uuid)
        ->call('confirmRetry')
        ->assertSet('pendingRetryUuid', null)
        ->assertSee('safely queued');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1);

    Log::shouldHaveReceived('notice')->twice();

    expect((new ReflectionClass(RetryFailedJob::class))->getConstant('ALLOWED_JOBS'))
        ->toHaveKey(ScanMovieLibrary::class, 'Scan movie library');
});

it('rejects non-administrators and non-allowlisted failed jobs', function () {
    $user = User::factory()->create(['is_administrator' => false]);
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['commandName' => 'App\\Jobs\\UnreviewedJob']], JSON_THROW_ON_ERROR),
        'exception' => 'Hidden exception',
        'failed_at' => now(),
    ]);

    $retry = app(RetryFailedJob::class);

    expect(fn () => $retry->execute($uuid, $user))->toThrow(AuthorizationException::class)
        ->and(fn () => $retry->execute($uuid, User::factory()->create(['is_administrator' => true])))
        ->toThrow(RuntimeException::class, 'not approved');
});

it('records process heartbeats and filters expected Pulse exceptions', function () {
    ProcessHeartbeat::recordScheduler();
    ProcessHeartbeat::recordQueueWorker();

    expect(Cache::get(ProcessHeartbeat::SCHEDULER_KEY))->toBeInt()
        ->and(Cache::get(ProcessHeartbeat::QUEUE_WORKER_KEY))->toBeInt()
        ->and(collect(config('pulse.recorders.'.Exceptions::class.'.ignore'))
            ->contains(fn (string $pattern): bool => preg_match($pattern, ValidationException::class) === 1))
        ->toBeTrue()
        ->and(config('pulse.storage.trim.keep'))->toBe('7 days')
        ->and(config('pulse.ingest.trim.keep'))->toBe('7 days');
});

it('keeps application database and cache liveness on the framework health route', function () {
    $this->get('/up')->assertSuccessful();
});

it('schedules upload expiry and processing recovery without overlap', function () {
    $events = collect(app(Schedule::class)->events());
    $expiry = $events->first(fn ($event): bool => str_contains((string) $event->command, 'uploads:expire-inactive'));
    $recovery = $events->first(fn ($event): bool => str_contains((string) $event->command, 'uploads:recover-processing'));

    expect($expiry)->not->toBeNull()
        ->and($expiry->expression)->toBe('*/15 * * * *')
        ->and($expiry->withoutOverlapping)->toBeTrue()
        ->and($recovery)->not->toBeNull()
        ->and($recovery->expression)->toBe('*/5 * * * *')
        ->and($recovery->withoutOverlapping)->toBeTrue();
});

it('renders the custom operational health states for administrators', function () {
    $administrator = User::factory()->administrator()->create();
    $upload = Upload::factory()->create();
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Failed->value,
        'failed_at' => now(),
    ]);
    LibraryScan::factory()->create([
        'status' => 'failed',
        'completed_at' => null,
    ]);
    ProcessHeartbeat::recordScheduler();
    ProcessHeartbeat::recordQueueWorker();
    config()->set('media.disks', []);

    Livewire::actingAs($administrator)
        ->test(MoviePipelineHealth::class, ['lazy' => false])
        ->assertViewHas('metrics', function (array $metrics): bool {
            $failedUploads = collect($metrics)->firstWhere('name', 'Failed uploads');
            $failedScans = collect($metrics)->firstWhere('name', 'Failed scans');

            return is_array($failedUploads)
                && $failedUploads['value'] === 1
                && $failedUploads['warning'] === true
                && is_array($failedScans)
                && $failedScans['value'] === 1
                && $failedScans['warning'] === true;
        });

    Livewire::actingAs($administrator)
        ->test(ProcessHealth::class, ['lazy' => false])
        ->assertViewHas('processes', function (array $processes): bool {
            $healthyProcesses = collect($processes)->where('healthy', true)->pluck('name');

            return $healthyProcesses->contains('Scheduler')
                && $healthyProcesses->contains('Queue worker');
        });

    Livewire::actingAs($administrator)
        ->test(MediaDiskHealth::class, ['lazy' => false])
        ->assertViewHas('configurationError', null)
        ->assertViewHas('disks', []);
});

it('stores Pulse key hashes without a MySQL generated column', function () {
    app(Storage::class)->store(collect([
        new Value(now()->getTimestamp(), 'operations-test', 'a long pulse storage key', 'healthy'),
    ]));

    expect(DB::table('pulse_values')
        ->where('type', 'operations-test')
        ->where('key_hash', md5('a long pulse storage key'))
        ->exists())
        ->toBeTrue();
});

it('safely restores the object types used by Pulse dashboard caches', function () {
    $cacheKey = 'operations-test:pulse-object-cache';
    $pulseValue = [
        collect([(object) ['latest' => CarbonImmutable::now(), 'count' => 1]]),
        0.25,
        '2026-08-09 20:00:00',
    ];

    Cache::store('database')->put($cacheKey, $pulseValue, 60);
    $cachedValue = Cache::store('database')->get($cacheKey);

    expect(config('cache.serializable_classes'))
        ->toBe([CarbonImmutable::class, Collection::class, stdClass::class])
        ->and($cachedValue)->toBeArray()
        ->and($cachedValue[0])->toBeInstanceOf(Collection::class)
        ->and($cachedValue[0]->first())->toBeInstanceOf(stdClass::class)
        ->and($cachedValue[0]->first()->latest)->toBeInstanceOf(CarbonImmutable::class);
});

it('keeps production process supervision explicit', function () {
    $configuration = file_get_contents(base_path('deploy/supervisor/media-upload-manager.conf.example'));

    expect($configuration)
        ->toContain('queue:work')
        ->toContain('schedule:work')
        ->toContain('pulse:check');
});
