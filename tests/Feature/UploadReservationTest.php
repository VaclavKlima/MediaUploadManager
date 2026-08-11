<?php

use App\Actions\CreateOrReplayUploadReservation;
use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\DiskMarker;
use App\Support\Media\NativeMediaFilesystem;
use App\Support\Media\TrackedMovieDeletionClaim;
use App\Support\Media\UploadConfiguration;
use App\ValueObjects\TokenHash;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @param  list<array{id: string, label: string, path: string, reserve_gib: string}>  $disks
 * @param  array<string, int>  $freeBytesByRoot
 */
function configureReservationDisks(array $disks, array $freeBytesByRoot): void
{
    config()->set('media', [
        'disks' => $disks,
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    config()->set('upload', [
        'tus_public_path' => '/uploads/tus/',
        'token_ttl_seconds' => '900',
        'inactivity_seconds' => '604800',
        'fingerprint_window_bytes' => '1048576',
    ]);

    $filesystem = new class($freeBytesByRoot) extends NativeMediaFilesystem
    {
        /** @param array<string, int> $freeBytesByRoot */
        public function __construct(private readonly array $freeBytesByRoot) {}

        public function capacity(string $path): ?array
        {
            $freeBytes = $this->freeBytesByRoot[$path] ?? null;

            return $freeBytes === null ? null : [
                'total' => $freeBytes * 2,
                'free' => $freeBytes,
            ];
        }

        public function probe(string $directory): bool
        {
            return true;
        }
    };

    app()->instance(MediaFilesystem::class, $filesystem);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array<string, int|string|null> */
function reservationPayload(array $overrides = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'filename' => 'The.Matrix.1999.MKV',
        'declared_size' => 6_000,
        'last_modified_milliseconds' => 1_754_000_000_000,
        'fingerprint_first_sha256' => hash('sha256', 'first-window'),
        'fingerprint_last_sha256' => hash('sha256', 'last-window'),
        'disk_id' => 'movies_a',
        ...$overrides,
    ];
}

/** @return array{directories: list<string>, files: list<string>} */
function reservationTree(Filesystem $filesystem, string $root): array
{
    $directories = $filesystem->allDirectories($root);
    $files = array_map(
        fn (SplFileInfo $file): string => $file->getPathname(),
        $filesystem->allFiles($root),
    );
    sort($directories);
    sort($files);

    return ['directories' => $directories, 'files' => $files];
}

function reservationCurrentPrimary(MediaItem $mediaItem, User $owner, string $root): MediaFile
{
    $relativePath = 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv';
    $sourceUpload = Upload::factory()->for($owner)->for($mediaItem)->create([
        'disk_id' => 'movies_b',
        'target_relative_path' => $relativePath,
        'declared_size' => 12,
    ]);
    Upload::query()->whereKey($sourceUpload)->update([
        'status' => UploadStatus::Completed->value,
        'confirmed_offset' => 12,
        'completed_at' => now(),
        'expires_at' => null,
    ]);
    $mediaFile = MediaFile::factory()->forUpload($sourceUpload->refresh())->create([
        'disk_id' => 'movies_b',
        'relative_path' => $relativePath,
        'size_bytes' => 12,
    ]);
    $mediaItem->update(['current_media_file_id' => $mediaFile->getKey()]);

    (new Filesystem)->makeDirectory(dirname($root.'/'.$relativePath), 0750, true);
    file_put_contents($root.'/'.$relativePath, 'old-primary!');

    return $mediaFile;
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-06 12:00:00');
    $this->filesystem = new Filesystem;
    $this->reservationBase = storage_path('framework/testing/reservation-'.bin2hex(random_bytes(6)));
    $this->reservationA = $this->reservationBase.'/a';
    $this->reservationB = $this->reservationBase.'/b';

    foreach ([
        'movies_a' => $this->reservationA,
        'movies_b' => $this->reservationB,
    ] as $diskId => $root) {
        $this->filesystem->makeDirectory($root.'/.media-upload-manager/incoming', 0750, true);
        file_put_contents($root.'/.media-upload-manager/disk.json', DiskMarker::encode($diskId));
    }

    configureReservationDisks([
        ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $this->reservationA, 'reserve_gib' => '0'],
        ['id' => 'movies_b', 'label' => 'Movies B', 'path' => $this->reservationB, 'reserve_gib' => '0'],
    ], [
        $this->reservationA => 20_000,
        $this->reservationB => 15_000,
    ]);

    $this->reservationMovie = MediaItem::factory()->create([
        'title' => 'The Matrix',
        'release_year' => 1999,
        'tmdb_id' => 603,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    $this->filesystem->deleteDirectory($this->reservationBase);
});

it('requires authentication and completed credential onboarding', function () {
    $payload = reservationPayload();

    $this->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertUnauthorized();

    $this->actingAs(User::factory()->credentialChangeRequired()->create())
        ->post(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertRedirect(route('onboarding.edit'));
});

it('validates admission inputs with safe JSON errors', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create())
        ->postJson(
            route('movies.uploads.store', $this->reservationMovie),
            reservationPayload($overrides),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor($field);
})->with([
    'idempotency key' => [['idempotency_key' => 'not-a-uuid'], 'idempotency_key'],
    'unsafe filename' => [['filename' => '../movie.mkv'], 'filename'],
    'empty file' => [['declared_size' => 0], 'declared_size'],
    'negative modification time' => [['last_modified_milliseconds' => -1], 'last_modified_milliseconds'],
    'uppercase first fingerprint' => [['fingerprint_first_sha256' => str_repeat('A', 64)], 'fingerprint_first_sha256'],
    'invalid last fingerprint' => [['fingerprint_last_sha256' => str_repeat('z', 64)], 'fingerprint_last_sha256'],
    'unsafe disk ID' => [['disk_id' => '../movies'], 'disk_id'],
]);

it('creates a pending reservation with canonical server paths and a one-time token', function () {
    $beforeA = reservationTree($this->filesystem, $this->reservationA);
    $beforeB = reservationTree($this->filesystem, $this->reservationB);
    $user = User::factory()->create();
    $payload = reservationPayload(['disk_id' => 'movies_b']);

    $response = $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertCreated()
        ->assertJsonPath('data.media_item_id', $this->reservationMovie->getKey())
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.disk.id', 'movies_b')
        ->assertJsonPath('data.disk.label', 'Movies B')
        ->assertJsonPath('data.target_relative_path', 'The Matrix (1999) [tmdbid-603]/The Matrix (1999) [tmdbid-603].mkv')
        ->assertJsonPath('data.declared_bytes', 6_000)
        ->assertJsonPath('data.confirmed_bytes', 0)
        ->assertJsonPath('data.expires_at', '2026-08-13T12:00:00.000000Z')
        ->assertJsonPath('data.tus_endpoint', '/uploads/tus/')
        ->assertJsonPath('data.authorization.abilities', CreateOrReplayUploadReservation::TOKEN_ABILITIES)
        ->assertJsonPath('data.authorization.expires_at', '2026-08-06T12:15:00.000000Z')
        ->assertJsonPath('data.idempotent_replay', false);

    $upload = Upload::query()->sole();
    $plaintextToken = $response->json('data.authorization.token');

    expect($upload->user_id)->toBe($user->id)
        ->and($upload->idempotency_key)->toBe(Str::lower($payload['idempotency_key']))
        ->and($upload->staging_relative_path)->toBe('.media-upload-manager/incoming/'.$upload->uuid.'.part')
        ->and($upload->token_hash)->not->toBe($plaintextToken)
        ->and(TokenHash::fromHash((string) $upload->token_hash)->matches((string) $plaintextToken))->toBeTrue()
        ->and($response->getContent())->not->toContain('token_hash')
        ->not->toContain($this->reservationBase)
        ->and(reservationTree($this->filesystem, $this->reservationA))->toBe($beforeA)
        ->and(reservationTree($this->filesystem, $this->reservationB))->toBe($beforeB);
});

it('rejects admission after a movie deletion claim is durably recorded', function () {
    $claim = TrackedMovieDeletionClaim::forOrphan(
        $this->reservationMovie->getKey(),
        123,
        $this->reservationMovie->title,
    );
    $this->reservationMovie->update([
        'deletion_claim' => $claim->toArray(),
        'deletion_requested_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload())
        ->assertConflict()
        ->assertExactJson([
            'error' => 'movie_deletion_in_progress',
            'message' => 'This movie has a confirmed permanent deletion in progress.',
        ]);

    expect(Upload::query()->count())->toBe(0);
});

it('returns a stable safe error for invalid upload configuration', function () {
    config()->set('upload.token_ttl_seconds', '0');
    app()->forgetInstance(UploadConfiguration::class);

    $this->actingAs(User::factory()->create())
        ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload())
        ->assertServiceUnavailable()
        ->assertExactJson([
            'error' => 'upload_configuration_invalid',
            'message' => 'Upload configuration is unavailable.',
        ]);

    expect(Upload::query()->count())->toBe(0);
});

it('replays an exact pending reservation once and rotates its token and expiry', function () {
    $user = User::factory()->create();
    $payload = reservationPayload();
    $first = $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertCreated();
    $firstToken = $first->json('data.authorization.token');
    $uuid = $first->json('data.uuid');

    Carbon::setTestNow('2026-08-06 13:00:00');
    $second = $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.uuid', $uuid)
        ->assertJsonPath('data.idempotent_replay', true)
        ->assertJsonPath('data.expires_at', '2026-08-13T13:00:00.000000Z')
        ->assertJsonPath('data.authorization.expires_at', '2026-08-06T13:15:00.000000Z');

    $upload = Upload::query()->sole();
    $secondToken = $second->json('data.authorization.token');

    expect(Upload::query()->count())->toBe(1)
        ->and($secondToken)->not->toBe($firstToken)
        ->and(TokenHash::fromHash((string) $upload->token_hash)->matches((string) $secondToken))->toBeTrue()
        ->and(TokenHash::fromHash((string) $upload->token_hash)->matches((string) $firstToken))->toBeFalse();
});

it('rejects changed or inactive reuse of an idempotency key', function (string $case) {
    $user = User::factory()->create();
    $payload = reservationPayload();
    $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertCreated();

    if ($case === 'inactive') {
        Upload::query()->update(['expires_at' => now()->subSecond()]);
    } else {
        $payload['declared_size'] = 6_001;
    }

    $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertConflict()
        ->assertExactJson([
            'error' => 'idempotency_conflict',
            'message' => 'The idempotency key was already used for a different or inactive reservation.',
        ]);
})->with(['changed payload', 'inactive']);

it('rejects unavailable disks capacity shortfalls and stale movie conflicts safely', function (string $case, string $error) {
    if ($case === 'unavailable') {
        unlink($this->reservationA.'/.media-upload-manager/disk.json');
    } elseif ($case === 'capacity') {
        configureReservationDisks([
            ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $this->reservationA, 'reserve_gib' => '0'],
        ], [$this->reservationA => 5_999]);
    } else {
        Upload::factory()->for($this->reservationMovie)->create(['disk_id' => 'movies_b']);
    }

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload())
        ->assertConflict()
        ->assertJsonPath('error', $error);

    expect($response->getContent())->not->toContain($this->reservationBase);
})->with([
    'unavailable' => ['unavailable', 'disk_unavailable'],
    'capacity' => ['capacity', 'insufficient_capacity'],
    'conflict' => ['conflict', 'upload_conflict'],
]);

it('serializes admission and fails safely when the database lock is held', function () {
    Carbon::setTestNow();
    $lock = Cache::store('database')->lock(CreateOrReplayUploadReservation::ADMISSION_LOCK_NAME, 30);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs(User::factory()->create())
            ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload())
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'admission_lock_timeout',
                'message' => 'Upload admission is busy. Please try again.',
            ]);
    } finally {
        $lock->release();
    }

    expect(Upload::query()->count())->toBe(0);
});

it('does not overcommit capacity across sequential serialized admissions', function () {
    configureReservationDisks([
        ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $this->reservationA, 'reserve_gib' => '0'],
    ], [$this->reservationA => 10_000]);
    $user = User::factory()->create();
    $firstMovie = $this->reservationMovie;
    $secondMovie = MediaItem::factory()->create([
        'title' => 'The Matrix Reloaded',
        'release_year' => 2003,
        'tmdb_id' => 604,
    ]);

    $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $firstMovie), reservationPayload(['declared_size' => 6_000]))
        ->assertCreated();
    $this->actingAs($user)
        ->postJson(route('movies.uploads.store', $secondMovie), reservationPayload([
            'idempotency_key' => (string) Str::uuid(),
            'filename' => 'The.Matrix.Reloaded.2003.mkv',
            'declared_size' => 4_001,
        ]))
        ->assertConflict()
        ->assertJsonPath('error', 'insufficient_capacity');

    expect(Upload::query()->count())->toBe(1);
});

it('allows owners and administrators to idempotently cancel and revoke pending reservations', function (string $actorType) {
    $owner = User::factory()->create();
    $actor = $actorType === 'owner' ? $owner : User::factory()->administrator()->create();
    $created = $this->actingAs($owner)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload())
        ->assertCreated();
    $uuid = $created->json('data.uuid');

    $this->actingAs($actor)
        ->deleteJson(route('uploads.destroy', $uuid))
        ->assertSuccessful()
        ->assertJsonPath('data.uuid', $uuid)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_at', '2026-08-06T12:00:00.000000Z');
    $this->actingAs($actor)
        ->deleteJson(route('uploads.destroy', $uuid))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    $upload = Upload::query()->where('uuid', $uuid)->sole();
    expect($upload->status)->toBe(UploadStatus::Cancelled)
        ->and($upload->token_hash)->toBeNull()
        ->and($upload->token_abilities)->toBeNull()
        ->and($upload->token_expires_at)->toBeNull();

    $this->actingAs($owner)
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->reservationMovie,
            'filename' => 'The.Matrix.1999.mkv',
            'declared_size' => 6_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.disks.0.active_reserved_bytes', 0);
})->with(['owner', 'administrator']);

it('denies cross-user cancellation without revoking the token', function () {
    $owner = User::factory()->create();
    $uuid = $this->actingAs($owner)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload())
        ->assertCreated()
        ->json('data.uuid');
    $upload = Upload::query()->where('uuid', $uuid)->sole();
    $tokenHash = $upload->token_hash;

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('uploads.destroy', $uuid))
        ->assertForbidden();

    expect($upload->refresh()->status)->toBe(UploadStatus::Pending)
        ->and($upload->token_hash)->toBe($tokenHash);
});

it('previews the exact owner replacement on every eligible disk and denies other users', function () {
    $owner = User::factory()->create();
    $current = reservationCurrentPrimary($this->reservationMovie, $owner, $this->reservationB);

    $this->actingAs($owner)
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->reservationMovie,
            'filename' => 'The.Matrix.1999.mkv',
            'declared_size' => 6_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.can_start_new_upload', false)
        ->assertJsonPath('data.can_replace_current_primary', true)
        ->assertJsonPath('data.replaceable.id', $current->getKey())
        ->assertJsonPath('data.replaceable.disk.id', 'movies_b')
        ->assertJsonPath('data.replaceable.relative_path', $current->relative_path)
        ->assertJsonPath('data.replaceable.size_bytes', 12)
        ->assertJsonPath('data.disks.0.status', 'replaceable')
        ->assertJsonPath('data.disks.0.replacement_method', 'finalize_then_delete')
        ->assertJsonPath('data.disks.1.status', 'replaceable')
        ->assertJsonPath('data.disks.1.replacement_method', 'atomic_same_path_swap');

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->reservationMovie,
            'filename' => 'The.Matrix.1999.mkv',
            'declared_size' => 6_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.can_replace_current_primary', false)
        ->assertJsonPath('data.replaceable', null);
});

it('admits confirmed owner and administrator replacements and audits confirmation', function (string $actorType) {
    $owner = User::factory()->create();
    $actor = $actorType === 'owner' ? $owner : User::factory()->administrator()->create();
    $current = reservationCurrentPrimary($this->reservationMovie, $owner, $this->reservationB);
    Log::spy();

    $response = $this->actingAs($actor)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), reservationPayload([
            'disk_id' => 'movies_a',
            'replaces_media_file_id' => $current->getKey(),
            'replacement_confirmed' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.replacement.media_file_id', $current->getKey())
        ->assertJsonPath('data.replacement.disk.id', 'movies_b')
        ->assertJsonPath('data.replacement.method', 'finalize_then_delete');

    $upload = Upload::query()->where('uuid', $response->json('data.uuid'))->sole();

    expect($upload->replaces_media_file_id)->toBe($current->getKey())
        ->and($upload->replacement_confirmed_at)->not->toBeNull();
    Log::shouldHaveReceived('notice')->once()->with('security.audit', Mockery::on(
        fn (array $context): bool => $context['event'] === 'media_replacement_confirmed'
            && $context['upload_id'] === $upload->getKey()
            && ! array_key_exists('token', $context),
    ));
})->with(['owner', 'administrator']);

it('requires irreversible confirmation and rejects stale or unauthorized replacement identities', function (string $case) {
    $owner = User::factory()->create();
    $current = reservationCurrentPrimary($this->reservationMovie, $owner, $this->reservationB);
    $actor = $case === 'unauthorized' ? User::factory()->create() : $owner;
    $payload = reservationPayload([
        'replaces_media_file_id' => $current->getKey(),
        'replacement_confirmed' => true,
    ]);

    if ($case === 'missing confirmation') {
        unset($payload['replacement_confirmed']);
    } elseif ($case === 'stale') {
        $otherUpload = Upload::factory()->for($this->reservationMovie)->create();
        $otherFile = MediaFile::factory()->forUpload($otherUpload)->create();
        $this->reservationMovie->update(['current_media_file_id' => $otherFile->getKey()]);
    }

    $response = $this->actingAs($actor)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload);

    if ($case === 'missing confirmation') {
        $response->assertUnprocessable()->assertJsonValidationErrorFor('replacement_confirmed');
    } else {
        $response->assertConflict()->assertJsonPath('error', 'replacement_conflict');
    }
})->with(['missing confirmation', 'stale', 'unauthorized']);

it('replays only an exact confirmed replacement and blocks a concurrent replacement', function () {
    $owner = User::factory()->create();
    $current = reservationCurrentPrimary($this->reservationMovie, $owner, $this->reservationB);
    $payload = reservationPayload([
        'replaces_media_file_id' => $current->getKey(),
        'replacement_confirmed' => true,
    ]);

    $first = $this->actingAs($owner)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertCreated();
    $this->actingAs($owner)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), $payload)
        ->assertSuccessful()
        ->assertJsonPath('data.uuid', $first->json('data.uuid'))
        ->assertJsonPath('data.idempotent_replay', true);

    $this->actingAs($owner)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), [
            ...$payload,
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertConflict()
        ->assertJsonPath('error', 'replacement_conflict');

    $this->actingAs($owner)
        ->postJson(route('movies.uploads.store', $this->reservationMovie), [
            ...$payload,
            'replacement_confirmed' => false,
        ])
        ->assertUnprocessable();
});

it('fails replacement preview closed for unsafe old bytes and extra active uploads', function (string $case) {
    $owner = User::factory()->create();
    $current = reservationCurrentPrimary($this->reservationMovie, $owner, $this->reservationB);
    $oldPath = $this->reservationB.'/'.$current->relative_path;

    if ($case === 'missing') {
        unlink($oldPath);
    } elseif ($case === 'size mismatch') {
        file_put_contents($oldPath, 'bad');
    } elseif ($case === 'symlink') {
        unlink($oldPath);
        file_put_contents($this->reservationBase.'/outside', 'old-primary!');
        symlink($this->reservationBase.'/outside', $oldPath);
    } else {
        Upload::factory()->for($this->reservationMovie)->create(['disk_id' => 'movies_a']);
    }

    $this->actingAs($owner)
        ->getJson(route('movies.path_preview', [
            'mediaItem' => $this->reservationMovie,
            'filename' => 'The.Matrix.1999.mkv',
            'declared_size' => 6_000,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.can_replace_current_primary', false)
        ->assertJsonPath('data.replaceable', null);
})->with(['missing', 'size mismatch', 'symlink', 'extra upload']);
