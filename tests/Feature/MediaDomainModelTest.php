<?php

use App\Actions\CreateOrReuseMediaItem;
use App\Enums\UploadStatus;
use App\Models\MediaFile;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\ValueObjects\TokenHash;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('creates the media domain schema with required indexes and foreign keys', function () {
    expect(Schema::hasColumns('media_items', [
        'tmdb_id',
        'imdb_id',
        'metadata_snapshot',
        'current_media_file_id',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('media_files', [
            'source_upload_id',
            'disk_id',
            'relative_path',
            'active_path_key',
            'probe_snapshot',
            'replaced_by_media_file_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('uploads', [
            'uuid',
            'confirmed_offset',
            'token_hash',
            'replaces_media_file_id',
            'replacement_confirmed_at',
        ]))->toBeTrue();

    $mediaFileIndexes = collect(Schema::getIndexes('media_files'))->pluck('name');
    $uploadForeignKeys = collect(Schema::getForeignKeys('uploads'))->pluck('columns')->flatten();

    expect($mediaFileIndexes)->toContain('media_files_active_path_key_unique')
        ->and($uploadForeignKeys)->toContain('user_id', 'media_item_id', 'replaces_media_file_id');
});

it('creates factories with uuidv7, casts, large sizes, and relationships', function () {
    $upload = Upload::factory()->create([
        'declared_size' => 50_000_000_000,
        'confirmed_offset' => 10_000_000_000,
    ]);
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();

    expect(Str::isUuid($upload->uuid, version: 7))->toBeTrue()
        ->and($upload->status)->toBe(UploadStatus::Pending)
        ->and($upload->token_abilities)->toBeArray()
        ->and($upload->token_expires_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($upload->declared_size)->toBe(50_000_000_000)
        ->and($upload->user)->toBeInstanceOf(User::class)
        ->and($upload->mediaItem)->toBeInstanceOf(MediaItem::class)
        ->and($upload->mediaFile()->sole()->is($mediaFile))->toBeTrue()
        ->and($mediaFile->video_metadata)->toBeArray()
        ->and($mediaFile->finalized_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($mediaFile->sourceUpload()->sole()->is($upload))->toBeTrue();
});

it('reuses an existing tmdb identity without changing its snapshot', function () {
    $snapshot = [
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'title' => 'The Matrix',
        'original_title' => 'The Matrix',
        'release_date' => '1999-03-30',
        'release_year' => 1999,
        'overview' => 'A simulated reality.',
        'poster_path' => '/matrix.jpg',
        'original_language' => 'en',
        'metadata_version' => 1,
        'metadata_snapshot' => ['title' => 'The Matrix'],
    ];
    $action = new CreateOrReuseMediaItem;
    $original = $action->handle($snapshot);

    $duplicate = $action->handle([
        ...$snapshot,
        'title' => 'Changed upstream title',
        'metadata_snapshot' => ['title' => 'Changed upstream title'],
    ]);

    expect($duplicate->is($original))->toBeTrue()
        ->and($duplicate->title)->toBe('The Matrix')
        ->and($duplicate->metadata_snapshot)->toBe(['title' => 'The Matrix'])
        ->and(MediaItem::query()->where('tmdb_id', 603)->count())->toBe(1);
});

it('enforces unique tmdb and imdb identities', function (string $attribute) {
    $mediaItem = MediaItem::factory()->create();

    MediaItem::factory()->create([$attribute => $mediaItem->getAttribute($attribute)]);
})->with(['tmdb_id', 'imdb_id'])->throws(QueryException::class);

it('enforces unique disk paths and source uploads', function (string $attribute) {
    $upload = Upload::factory()->create();
    $mediaFile = MediaFile::factory()->forUpload($upload)->create();

    $attributes = $attribute === 'path'
        ? ['disk_id' => $mediaFile->disk_id, 'relative_path' => $mediaFile->relative_path]
        : ['source_upload_id' => $mediaFile->source_upload_id, 'media_item_id' => $mediaFile->media_item_id];

    MediaFile::factory()->create($attributes);
})->with(['path', 'source upload'])->throws(QueryException::class);

it('allows historical rows to retain a path while exactly one live row owns its active key', function () {
    $firstUpload = Upload::factory()->create();
    $firstFile = MediaFile::factory()->forUpload($firstUpload)->create();
    $firstFile->update([
        'replaced_at' => now(),
        'removed_at' => now(),
        'removal_reason' => 'replaced_without_backup',
    ]);
    $secondUpload = Upload::factory()->for($firstFile->mediaItem)->create([
        'disk_id' => $firstFile->disk_id,
        'target_relative_path' => $firstFile->relative_path,
    ]);
    $secondFile = MediaFile::factory()->forUpload($secondUpload)->create([
        'disk_id' => $firstFile->disk_id,
        'relative_path' => $firstFile->relative_path,
    ]);

    expect($firstFile->refresh()->active_path_key)->toBeNull()
        ->and($secondFile->active_path_key)->toBe(MediaFile::activePathKey(
            $firstFile->disk_id,
            $firstFile->relative_path,
        ));
});

it('tracks exactly one current primary file and retains historical rows', function () {
    $mediaItem = MediaItem::factory()->create();
    $firstUpload = Upload::factory()->for($mediaItem)->create();
    $secondUpload = Upload::factory()->for($mediaItem)->create();
    $firstFile = MediaFile::factory()->forUpload($firstUpload)->create();
    $secondFile = MediaFile::factory()->forUpload($secondUpload)->create();

    $mediaItem->update(['current_media_file_id' => $secondFile->id]);
    $firstFile->update([
        'replaced_by_media_file_id' => $secondFile->id,
        'replaced_at' => now(),
    ]);

    expect($mediaItem->currentMediaFile()->sole()->is($secondFile))->toBeTrue()
        ->and($mediaItem->mediaFiles()->count())->toBe(2)
        ->and($firstFile->refresh()->replacedBy()->sole()->is($secondFile))->toBeTrue();
});

it('rejects cross-movie current and replacement links', function (string $link) {
    $firstMovie = MediaItem::factory()->create();
    $secondMovie = MediaItem::factory()->create();
    $firstUpload = Upload::factory()->for($firstMovie)->create();
    $secondUpload = Upload::factory()->for($secondMovie)->create();
    $firstFile = MediaFile::factory()->forUpload($firstUpload)->create();
    $secondFile = MediaFile::factory()->forUpload($secondUpload)->create();

    if ($link === 'current') {
        $firstMovie->update(['current_media_file_id' => $secondFile->id]);

        return;
    }

    $firstFile->update(['replaced_by_media_file_id' => $secondFile->id]);
})->with(['current', 'replacement'])->throws(DomainException::class);

it('requires an explicitly confirmed same-movie replacement target', function () {
    $mediaItem = MediaItem::factory()->create();
    $upload = Upload::factory()->for($mediaItem)->create();
    $file = MediaFile::factory()->forUpload($upload)->create();
    $mediaItem->update(['current_media_file_id' => $file->id]);

    Upload::factory()->for($mediaItem)->create([
        'replaces_media_file_id' => $file->id,
        'replacement_confirmed_at' => null,
    ]);
})->throws(DomainException::class);

it('persists an explicitly confirmed replacement of the tracked current primary', function () {
    $mediaItem = MediaItem::factory()->create();
    $sourceUpload = Upload::factory()->for($mediaItem)->create();
    $currentFile = MediaFile::factory()->forUpload($sourceUpload)->create();
    $mediaItem->update(['current_media_file_id' => $currentFile->id]);

    $replacementUpload = Upload::factory()->for($mediaItem)->create([
        'replaces_media_file_id' => $currentFile->id,
        'replacement_confirmed_at' => now(),
    ]);

    expect($replacementUpload->replacesMediaFile()->sole()->is($currentFile))->toBeTrue()
        ->and($replacementUpload->replacement_confirmed_at)->not->toBeNull();
});

it('rejects replacement of a historical same-movie file', function () {
    $mediaItem = MediaItem::factory()->create();
    $historicalUpload = Upload::factory()->for($mediaItem)->create();
    $currentUpload = Upload::factory()->for($mediaItem)->create();
    $historicalFile = MediaFile::factory()->forUpload($historicalUpload)->create();
    $currentFile = MediaFile::factory()->forUpload($currentUpload)->create();
    $mediaItem->update(['current_media_file_id' => $currentFile->id]);

    Upload::factory()->for($mediaItem)->create([
        'replaces_media_file_id' => $historicalFile->id,
        'replacement_confirmed_at' => now(),
    ]);
})->throws(DomainException::class);

it('keeps movie snapshots, media metadata, and upload admission fields immutable', function (string $modelType) {
    if ($modelType === 'movie') {
        $model = MediaItem::factory()->create();
        $model->title = 'Mutated title';
    } elseif ($modelType === 'file') {
        $model = MediaFile::factory()->create();
        $model->size_bytes++;
    } else {
        $model = Upload::factory()->create();
        $model->disk_id = 'different-disk';
    }

    $model->save();
})->with(['movie', 'file', 'upload'])->throws(DomainException::class);

it('allows only one-way addition of missing dynamic-range metadata', function () {
    $mediaFile = MediaFile::factory()->create([
        'video_metadata' => [[
            'index' => 0,
            'codec' => 'hevc',
            'width' => 3840,
            'height' => 1600,
        ]],
    ]);

    expect($mediaFile->addMissingDynamicRangeMetadata([[
        'index' => 0,
        'codec' => 'hevc',
        'width' => 3840,
        'height' => 1600,
        'dynamic_range' => 'hdr10',
    ]]))->toBeTrue()
        ->and($mediaFile->refresh()->video_metadata[0]['dynamic_range'])->toBe('hdr10')
        ->and(fn () => $mediaFile->addMissingDynamicRangeMetadata([[
            'index' => 0,
            'codec' => 'hevc',
            'width' => 3840,
            'height' => 1600,
            'dynamic_range' => 'hlg',
        ]]))->toThrow(DomainException::class)
        ->and(fn () => $mediaFile->addMissingDynamicRangeMetadata([[
            'index' => 0,
            'codec' => 'av1',
            'width' => 3840,
            'height' => 1600,
            'dynamic_range' => 'hdr10',
        ]]))->toThrow(DomainException::class);
});

it('makes tus identity write-once and offsets monotonic and bounded', function () {
    $upload = Upload::factory()->create(['declared_size' => 1_000]);

    $upload->assignTusResourceId('tus-resource-1')->confirmOffset(600);

    expect($upload->confirmed_offset)->toBe(600)
        ->and(fn () => $upload->confirmOffset(599))->toThrow(DomainException::class)
        ->and(fn () => $upload->confirmOffset(1_001))->toThrow(DomainException::class)
        ->and(fn () => $upload->assignTusResourceId('tus-resource-2'))->toThrow(DomainException::class);
});

it('never serializes token hashes or stores plaintext token attributes', function () {
    $plaintext = 'plain-upload-token-that-must-not-leak';
    $upload = Upload::factory()->create([
        'token_hash' => TokenHash::fromPlaintext($plaintext)->value,
    ]);

    expect($upload->toArray())->not->toHaveKey('token_hash')
        ->and($upload->getAttributes())->not->toHaveKey('token')
        ->and(json_encode($upload))->not->toContain($plaintext);
});

it('preserves media history with restrictive foreign keys', function () {
    $upload = Upload::factory()->create();
    MediaFile::factory()->forUpload($upload)->create();

    $upload->user()->firstOrFail()->delete();
})->throws(QueryException::class);
