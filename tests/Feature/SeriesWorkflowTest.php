<?php

use App\Enums\MediaRootKind;
use App\Enums\SeriesBatchStatus;
use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\SeriesUploadBatch;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\DiskMarker;
use App\Support\Media\FinalizeProcessedUpload;
use App\Support\Media\NativeMediaFilesystem;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

function configureSeriesWorkflow(string $root): void
{
    config()->set('media', [
        'disks' => [[
            'id' => 'series_a',
            'label' => 'Series A',
            'path' => null,
            'movies_path' => null,
            'series_path' => $root,
            'reserve_gib' => '0',
        ]],
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    $uploadConfiguration = config('upload');
    config()->set('upload', [
        ...(is_array($uploadConfiguration) ? $uploadConfiguration : []),
        'tus_public_path' => '/uploads/tus/',
        'token_ttl_seconds' => '900',
        'inactivity_seconds' => '604800',
        'fingerprint_window_bytes' => '1048576',
    ]);

    app()->instance(MediaFilesystem::class, new class extends NativeMediaFilesystem
    {
        public function capacity(string $path): ?array
        {
            return ['total' => 1_000_000_000, 'free' => 900_000_000];
        }

        public function probe(string $directory): bool
        {
            return true;
        }
    });
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    app()->forgetInstance(UploadConfiguration::class);
}

/** @return array<string, mixed> */
function seriesBatchPayload(SeriesEpisode $episode, array $overrides = []): array
{
    $season = $episode->season;
    $identity = sprintf('S%02dE%02d', $season->season_number, $episode->episode_number);

    return [
        'idempotency_key' => (string) Str::uuid(),
        'disk_id' => 'series_a',
        'items' => [[
            'source_identity' => "Season 01/Example.{$identity}.mkv",
            'series_episode_id' => $episode->id,
            'declared_size' => 6_000,
            'last_modified_milliseconds' => 1_754_000_000_000,
            'fingerprint_first_sha256' => hash('sha256', 'first-window'),
            'fingerprint_last_sha256' => hash('sha256', 'last-window'),
            'replaces_media_file_id' => null,
            'replacement_confirmed' => false,
        ]],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function seriesBatchPreviewPayload(SeriesEpisode $episode): array
{
    $season = $episode->season;
    $identity = sprintf('S%02dE%02d', $season->season_number, $episode->episode_number);

    return ['items' => [[
        'source_identity' => "Season 01/Example.{$identity}.mkv",
        'series_episode_id' => $episode->id,
        'declared_size' => 6_000,
        'replaces_media_file_id' => null,
        'replacement_confirmed' => false,
    ]]];
}

function seriesProcessingProbeJson(): string
{
    return json_encode([
        'streams' => [[
            'index' => 0,
            'codec_name' => 'h264',
            'codec_type' => 'video',
            'width' => 1920,
            'height' => 1080,
            'disposition' => ['default' => 1],
        ]],
        'format' => ['format_name' => 'matroska,webm', 'duration' => '42.5'],
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->seriesFilesystem = new Filesystem;
    $this->seriesRoot = storage_path('framework/testing/series-'.bin2hex(random_bytes(6)));
    $this->seriesFilesystem->makeDirectory($this->seriesRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->seriesRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('series_a', MediaRootKind::Series));
    configureSeriesWorkflow($this->seriesRoot);

    $this->series = Series::factory()->create(['name' => 'Example', 'first_air_year' => 2026, 'tmdb_id' => 99]);
    $this->season = SeriesSeason::factory()->for($this->series)->create(['season_number' => 1]);
    $this->episode = SeriesEpisode::factory()->for($this->season, 'season')->create(['episode_number' => 1, 'name' => 'Pilot']);
});

afterEach(function () {
    $this->seriesFilesystem->deleteDirectory($this->seriesRoot);
});

it('previews a lightweight batch with canonical destinations and full capacity projections', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(
            route('series.batches.preview', $this->series),
            seriesBatchPreviewPayload($this->episode),
        )
        ->assertSuccessful()
        ->assertJsonPath('data.series.id', $this->series->id)
        ->assertJsonPath('data.declared_bytes', 6_000)
        ->assertJsonPath('data.recommended_disk_id', 'series_a')
        ->assertJsonPath('data.can_start_batch', true)
        ->assertJsonPath('data.items.0.episode_identity', 'S01E01')
        ->assertJsonPath(
            'data.items.0.target_relative_path',
            'Example (2026) [tmdbid-99]/Season 01/Example S01E01 - Pilot/Example S01E01 - Pilot.mkv',
        )
        ->assertJsonPath('data.disks.0.health', 'healthy')
        ->assertJsonPath('data.disks.0.free_bytes', 900_000_000)
        ->assertJsonPath('data.disks.0.active_reserved_bytes', 0)
        ->assertJsonPath('data.disks.0.projected_usable_bytes', 899_994_000)
        ->assertJsonPath('data.disks.0.eligible', true);
});

it('recommends the eligible root with the greatest projected capacity', function () {
    $secondRoot = storage_path('framework/testing/series-'.bin2hex(random_bytes(6)));
    $this->seriesFilesystem->makeDirectory($secondRoot.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($secondRoot.'/.media-upload-manager/disk.json', DiskMarker::encode('series_b', MediaRootKind::Series));
    config()->set('media.disks', [
        [
            'id' => 'series_a', 'label' => 'Series A', 'path' => null,
            'movies_path' => null, 'series_path' => $this->seriesRoot, 'reserve_gib' => '0',
        ],
        [
            'id' => 'series_b', 'label' => 'Series B', 'path' => null,
            'movies_path' => null, 'series_path' => $secondRoot, 'reserve_gib' => '0',
        ],
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
    Upload::factory()->forSeriesEpisode($this->episode)->create([
        'disk_id' => 'series_a',
        'declared_size' => 100_000_000,
        'confirmed_offset' => 0,
        'status' => UploadStatus::Pending,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.preview', $this->series), seriesBatchPreviewPayload($this->episode))
        ->assertSuccessful()
        ->assertJsonPath('data.recommended_disk_id', 'series_b')
        ->assertJsonCount(2, 'data.disks');

    $this->seriesFilesystem->deleteDirectory($secondRoot);
});

it('returns only the immutable assigned root including an explicit missing configuration result', function () {
    $this->series->update(['home_disk_id' => 'series_a']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.preview', $this->series), seriesBatchPreviewPayload($this->episode))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.disks')
        ->assertJsonPath('data.disks.0.id', 'series_a');

    $missingSeries = Series::factory()->create([
        'name' => 'Missing Root',
        'home_disk_id' => 'retired_root',
    ]);
    $missingSeason = SeriesSeason::factory()->for($missingSeries)->create(['season_number' => 1]);
    $missingEpisode = SeriesEpisode::factory()->for($missingSeason, 'season')->create(['episode_number' => 1]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.preview', $missingSeries), seriesBatchPreviewPayload($missingEpisode))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.disks')
        ->assertJsonPath('data.disks.0.id', 'retired_root')
        ->assertJsonPath('data.disks.0.eligible', false)
        ->assertJsonPath('data.disks.0.reasons.0.code', 'configured_root_missing')
        ->assertJsonPath('data.recommended_disk_id', null);
});

it('previews exact replacement details and revalidates capacity during admission', function () {
    $owner = User::factory()->create();
    $sourceUpload = Upload::factory()->for($owner)->forSeriesEpisode($this->episode)->create();
    $currentPrimary = MediaFile::factory()->forUpload($sourceUpload)->create([
        'relative_path' => 'Example/Season 01/old.mkv',
        'size_bytes' => 5_000,
    ]);
    $this->episode->update(['current_media_file_id' => $currentPrimary->id]);
    $previewPayload = seriesBatchPreviewPayload($this->episode);
    $previewPayload['items'][0]['replaces_media_file_id'] = $currentPrimary->id;
    $previewPayload['items'][0]['replacement_confirmed'] = true;

    $this->actingAs($owner)
        ->postJson(route('series.batches.preview', $this->series), $previewPayload)
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.replacement.media_file_id', $currentPrimary->id)
        ->assertJsonPath('data.items.0.replacement.relative_path', 'Example/Season 01/old.mkv')
        ->assertJsonPath('data.items.0.replacement.size_bytes', 5_000);

    Upload::factory()->forSeriesEpisode($this->episode)->create([
        'disk_id' => 'series_a',
        'declared_size' => 899_999_000,
        'confirmed_offset' => 0,
        'status' => UploadStatus::Pending,
    ]);
    $storePayload = seriesBatchPayload($this->episode);
    $storePayload['items'][0]['replaces_media_file_id'] = $currentPrimary->id;
    $storePayload['items'][0]['replacement_confirmed'] = true;

    $this->actingAs($owner)
        ->postJson(route('series.batches.store', $this->series), $storePayload)
        ->assertConflict()
        ->assertJsonPath('error', 'insufficient_capacity');

    expect($this->series->refresh()->home_disk_id)->toBeNull()
        ->and($this->series->uploadBatches()->count())->toBe(0);
});

it('admits the complete batch atomically and permanently assigns the home disk', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('series.batches.store', $this->series), seriesBatchPayload($this->episode))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.home_disk.id', 'series_a')
        ->assertJsonPath('data.items.0.position', 1)
        ->assertJsonPath('data.items.0.episode.identity', 'S01E01')
        ->assertJsonPath('data.items.0.destination', 'Example (2026) [tmdbid-99]/Season 01/Example S01E01 - Pilot/Example S01E01 - Pilot.mkv');

    expect($this->series->refresh()->home_disk_id)->toBe('series_a')
        ->and($this->series->uploadBatches()->count())->toBe(1)
        ->and($this->series->uploadBatches()->firstOrFail()->uploads()->count())->toBe(1);
});

it('lists only visible unfinished batches and never exposes admitted fingerprints', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $payload = seriesBatchPayload($this->episode);

    $this->actingAs($owner)
        ->postJson(route('series.batches.store', $this->series), $payload)
        ->assertCreated();
    $batch = $this->series->uploadBatches()->sole();
    $completed = SeriesUploadBatch::factory()
        ->for($owner)
        ->for($this->series)
        ->create(['status' => SeriesBatchStatus::Completed]);
    $foreign = SeriesUploadBatch::factory()
        ->for($other)
        ->for($this->series)
        ->create(['status' => SeriesBatchStatus::Paused]);

    $response = $this->actingAs($owner)
        ->getJson(route('series.batches.resumable'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $batch->uuid)
        ->assertJsonMissingPath('data.0.items.0.fingerprint_first_sha256')
        ->assertJsonMissingPath('data.0.items.0.fingerprint_last_sha256')
        ->assertJsonMissingPath('data.0.items.0.authorization')
        ->assertJsonMissingPath('data.0.items.0.staging_relative_path');

    expect($response->json('data.0.uuid'))->not->toBe($completed->uuid)
        ->and($response->json('data.0.uuid'))->not->toBe($foreign->uuid);
});

it('verifies the exact recovery file set under the batch lock', function () {
    $owner = User::factory()->create();
    $payload = seriesBatchPayload($this->episode);
    $secondEpisode = SeriesEpisode::factory()->for($this->season, 'season')->create([
        'episode_number' => 2,
        'name' => 'Second',
    ]);
    $secondItem = $payload['items'][0];
    $secondItem['source_identity'] = 'Season 01/Example.S01E02.mkv';
    $secondItem['series_episode_id'] = $secondEpisode->id;
    $secondItem['fingerprint_first_sha256'] = hash('sha256', 'second-first');
    $secondItem['fingerprint_last_sha256'] = hash('sha256', 'second-last');
    $payload['items'][] = $secondItem;
    $this->actingAs($owner)
        ->postJson(route('series.batches.store', $this->series), $payload)
        ->assertCreated();
    $batch = $this->series->uploadBatches()->sole();
    $recoveryItems = $batch->uploads()->get()->map(function (Upload $upload) use ($payload): array {
        $item = $payload['items'][($upload->batch_position ?? 1) - 1];

        return [
            'upload_uuid' => $upload->uuid,
            'source_identity' => $item['source_identity'],
            'filename' => basename($item['source_identity']),
            'declared_size' => $item['declared_size'],
            'last_modified_milliseconds' => $item['last_modified_milliseconds'],
            'fingerprint_first_sha256' => $item['fingerprint_first_sha256'],
            'fingerprint_last_sha256' => $item['fingerprint_last_sha256'],
        ];
    })->all();

    $this->actingAs($owner)
        ->postJson(route('series.batches.recovery', $batch), ['items' => $recoveryItems])
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.source_identity', $payload['items'][0]['source_identity'])
        ->assertJsonMissingPath('data.items.0.fingerprint_first_sha256');

    $this->actingAs($owner)
        ->postJson(route('series.batches.recovery', $batch), ['items' => [$recoveryItems[0]]])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'series_recovery_set_mismatch');

    $recoveryItems[0]['fingerprint_first_sha256'] = hash('sha256', 'wrong');

    $this->actingAs($owner)
        ->postJson(route('series.batches.recovery', $batch), ['items' => $recoveryItems])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'series_recovery_fingerprint_mismatch');

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.recovery', $batch), ['items' => $recoveryItems])
        ->assertForbidden();
});

it('allows an explicitly acknowledged expired episode to become skipped', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)
        ->postJson(route('series.batches.store', $this->series), seriesBatchPayload($this->episode))
        ->assertCreated();
    $batch = $this->series->uploadBatches()->sole();
    $upload = $batch->uploads()->sole();
    $expiredAt = now()->subMinute();
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Expired->value,
        'expired_at' => $expiredAt,
    ]);
    $persistedExpiredAt = $upload->refresh()->expired_at?->toISOString();
    $batch->update(['status' => SeriesBatchStatus::Paused]);

    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', $upload))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    expect($upload->refresh()->expired_at?->toISOString())->toBe($persistedExpiredAt)
        ->and($upload->status)->toBe(UploadStatus::Cancelled)
        ->and($batch->refresh()->status)->toBe(SeriesBatchStatus::Cancelled);
});

it('discards a failed Series replacement only against its exact episode primary', function () {
    $owner = User::factory()->create();
    $relativePath = 'Example (2026) [tmdbid-99]/Season 01/Pilot.mkv';
    $sourceUpload = Upload::factory()
        ->for($owner)
        ->forSeriesEpisode($this->episode)
        ->create([
            'disk_id' => 'series_a',
            'target_relative_path' => $relativePath,
            'declared_size' => 8,
        ]);
    $currentPrimary = MediaFile::factory()->forUpload($sourceUpload)->create([
        'disk_id' => 'series_a',
        'relative_path' => $relativePath,
        'size_bytes' => 8,
    ]);
    $this->episode->update(['current_media_file_id' => $currentPrimary->id]);
    $targetPath = $this->seriesRoot.'/'.$relativePath;
    $this->seriesFilesystem->makeDirectory(dirname($targetPath), 0750, true);
    file_put_contents($targetPath, 'existing');
    $replacement = Upload::factory()
        ->for($owner)
        ->forSeriesEpisode($this->episode)
        ->create([
            'disk_id' => 'series_a',
            'target_relative_path' => $relativePath,
            'declared_size' => 12,
            'replaces_media_file_id' => $currentPrimary->id,
            'replacement_confirmed_at' => now(),
        ]);
    Upload::query()->whereKey($replacement)->update([
        'status' => UploadStatus::Failed->value,
        'error_code' => 'probe_failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', $replacement))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    expect(file_get_contents($targetPath))->toBe('existing')
        ->and($this->episode->refresh()->current_media_file_id)->toBe($currentPrimary->id)
        ->and($currentPrimary->refresh()->replaced_at)->toBeNull();
});

it('replays an exact batch and rejects a changed manifest under the same key', function () {
    $user = User::factory()->create();
    $payload = seriesBatchPayload($this->episode);

    $this->actingAs($user)->postJson(route('series.batches.store', $this->series), $payload)->assertCreated();
    $this->actingAs($user)->postJson(route('series.batches.store', $this->series), $payload)
        ->assertSuccessful()->assertJsonPath('idempotent_replay', true);
    $payload['items'][0]['declared_size'] = 7_000;
    $this->actingAs($user)->postJson(route('series.batches.store', $this->series), $payload)
        ->assertConflict()->assertJsonPath('error', 'idempotency_conflict');

    expect($this->series->uploadBatches()->count())->toBe(1);
});

it('rolls back every item when a source or episode is duplicated', function () {
    $payload = seriesBatchPayload($this->episode);
    $payload['items'][] = $payload['items'][0];

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.store', $this->series), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'duplicate_batch_item');

    expect($this->series->uploadBatches()->count())->toBe(0)
        ->and($this->series->refresh()->home_disk_id)->toBeNull();
});

it('rejects a destination already claimed by an active upload', function () {
    Upload::factory()->forSeriesEpisode($this->episode)->create([
        'disk_id' => 'series_a',
        'target_relative_path' => 'Example (2026) [tmdbid-99]/Season 01/Example S01E01 - Pilot/Example S01E01 - Pilot.mkv',
        'status' => UploadStatus::Pending,
        'declared_size' => 1_000,
        'confirmed_offset' => 0,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.store', $this->series), seriesBatchPayload($this->episode))
        ->assertConflict()
        ->assertJsonPath('error', 'upload_conflict');

    expect($this->series->refresh()->home_disk_id)->toBeNull()
        ->and($this->series->uploadBatches()->count())->toBe(0);
});

it('uses the selected series episode for unrecognized and mismatched filenames', function (string $sourceIdentity) {
    $payload = seriesBatchPayload($this->episode);
    $payload['items'][0]['source_identity'] = $sourceIdentity;

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.store', $this->series), $payload)
        ->assertCreated()
        ->assertJsonPath('data.items.0.episode.identity', 'S01E01')
        ->assertJsonPath('data.items.0.episode.title', 'Pilot');
})->with([
    'unrecognized name' => 'Season 01/Pilot.mkv',
    'mismatched identity' => 'Season 01/Example.S09E42.mkv',
]);

it('rejects cross-show episode IDs and unsafe multipart sources', function () {
    $otherSeries = Series::factory()->create();
    $otherSeason = SeriesSeason::factory()->for($otherSeries)->create(['season_number' => 1]);
    $otherEpisode = SeriesEpisode::factory()->for($otherSeason, 'season')->create(['episode_number' => 1]);
    $crossShowPayload = seriesBatchPayload($this->episode);
    $crossShowPayload['items'][0]['series_episode_id'] = $otherEpisode->getKey();

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.store', $this->series), $crossShowPayload)
        ->assertConflict()
        ->assertJsonPath('error', 'stale_episode_mapping');

    $multipartPayload = seriesBatchPayload($this->episode);
    $multipartPayload['items'][0]['source_identity'] = 'Season 01/Example.S01E01.Part.2.mkv';

    $this->actingAs(User::factory()->create())
        ->postJson(route('series.batches.store', $this->series), $multipartPayload)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'unsafe_source_file');

    expect($this->series->uploadBatches()->count())->toBe(0);
});

it('requires the exact current primary and owner permission for replacements', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $sourceUpload = Upload::factory()->for($owner)->forSeriesEpisode($this->episode)->create();
    $currentPrimary = MediaFile::factory()->forUpload($sourceUpload)->create();
    $staleUpload = Upload::factory()->for($owner)->forSeriesEpisode($this->episode)->create([
        'target_relative_path' => 'Example/Season 01/stale.mkv',
    ]);
    $stalePrimary = MediaFile::factory()->forUpload($staleUpload)->create();
    $this->episode->update(['current_media_file_id' => $currentPrimary->getKey()]);
    $payload = seriesBatchPayload($this->episode);
    $payload['items'][0]['replaces_media_file_id'] = $currentPrimary->getKey();
    $payload['items'][0]['replacement_confirmed'] = true;

    $this->actingAs($other)
        ->postJson(route('series.batches.store', $this->series), $payload)
        ->assertConflict()
        ->assertJsonPath('error', 'replacement_conflict');

    $payload['items'][0]['replaces_media_file_id'] = $stalePrimary->getKey();

    $this->actingAs($owner)
        ->postJson(route('series.batches.store', $this->series), $payload)
        ->assertConflict()
        ->assertJsonPath('error', 'replacement_conflict');

    expect($this->series->uploadBatches()->count())->toBe(0);
});

it('refuses just-in-time authorization for an episode before its predecessor is terminal', function () {
    $secondEpisode = SeriesEpisode::factory()->for($this->season, 'season')->create([
        'episode_number' => 2,
        'name' => 'Second',
    ]);
    $payload = seriesBatchPayload($this->episode);
    $secondItem = $payload['items'][0];
    $secondItem['source_identity'] = 'Season 01/Example.S01E02.mkv';
    $secondItem['series_episode_id'] = $secondEpisode->id;
    $secondItem['fingerprint_first_sha256'] = hash('sha256', 'second-first');
    $secondItem['fingerprint_last_sha256'] = hash('sha256', 'second-last');
    $payload['items'][] = $secondItem;
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('series.batches.store', $this->series), $payload)->assertCreated();
    $secondUpload = $this->series->uploadBatches()->sole()->uploads()->where('batch_position', 2)->sole();

    $this->actingAs($user)
        ->postJson(route('uploads.authorization', $secondUpload), [
            'filename' => 'Example.S01E02.mkv',
            'declared_size' => 6_000,
            'last_modified_milliseconds' => 1_754_000_000_000,
            'fingerprint_first_sha256' => hash('sha256', 'second-first'),
            'fingerprint_last_sha256' => hash('sha256', 'second-last'),
        ])
        ->assertConflict()
        ->assertJsonPath('error', 'series_batch_out_of_sequence');
});

it('finalizes an episode inside the Series root and advances its current primary', function () {
    $metadataPath = $this->seriesRoot.'/metadata';
    $this->seriesFilesystem->makeDirectory($metadataPath, 0750, true);
    config()->set('upload.tus_metadata_path', $metadataPath);
    config()->set('upload.ffprobe_binary', 'ffprobe');
    app()->forgetInstance(UploadConfiguration::class);
    $upload = Upload::factory()->forSeriesEpisode($this->episode)->create([
        'disk_id' => 'series_a',
        'target_relative_path' => 'Example (2026) [tmdbid-99]/Season 01/Example S01E01 - Pilot/Example S01E01 - Pilot.mkv',
        'declared_size' => 12,
    ]);
    Upload::query()->whereKey($upload)->update([
        'status' => UploadStatus::Processing->value,
        'confirmed_offset' => 12,
        'tus_resource_id' => $upload->uuid,
        'tus_creation_claimed_at' => now(),
        'tus_created_at' => now(),
        'processing_at' => now(),
    ]);
    $upload->refresh();
    $stagePath = $this->seriesRoot.'/'.$upload->staging_relative_path;
    file_put_contents($stagePath, 'episode-data');
    file_put_contents($metadataPath.'/'.$upload->uuid.'.info', json_encode([
        'ID' => $upload->uuid,
        'Size' => 12,
        'SizeIsDeferred' => false,
        'MetaData' => ['upload_uuid' => $upload->uuid],
        'IsPartial' => false,
        'IsFinal' => false,
        'PartialUploads' => null,
        'Storage' => ['Path' => $stagePath],
    ], JSON_THROW_ON_ERROR));
    Http::fake([
        'http://127.0.0.1:1080/uploads/tus/*' => Http::response('', 200, [
            'Upload-Offset' => '12', 'Upload-Length' => '12',
        ]),
    ]);
    Process::fake(fn (PendingProcess $process) => Process::result(output: seriesProcessingProbeJson()));

    app(FinalizeProcessedUpload::class)->process($upload);

    $mediaFile = MediaFile::query()->where('series_episode_id', $this->episode->id)->sole();

    expect($upload->refresh()->status)->toBe(UploadStatus::Completed)
        ->and($mediaFile->root_kind)->toBe(MediaRootKind::Series)
        ->and($this->episode->refresh()->current_media_file_id)->toBe($mediaFile->id)
        ->and($this->series->refresh()->last_episode_finalized_at)->not->toBeNull()
        ->and(file_exists($this->seriesRoot.'/'.$upload->target_relative_path))->toBeTrue();
});

it('keeps confirmed Series in the shared catalog after their media is removed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('series.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('series/Index')
            ->has('series.data', 1)
            ->where('series.data.0.name', 'Example')
            ->where('series.data.0.state', 'empty')
            ->where('series.data.0.coverage.episodes.available', 0));

    $upload = Upload::factory()->forSeriesEpisode($this->episode)->create();
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();
    $this->episode->update(['current_media_file_id' => $mediaFile->id]);
    $this->series->update(['last_episode_finalized_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->get(route('series.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('series/Index')
            ->has('series.data', 1)
            ->where('series.data.0.name', 'Example')
            ->where('series.data.0.coverage.episodes.available', 1));
});
