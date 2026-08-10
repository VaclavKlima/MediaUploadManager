<?php

use App\Models\LibraryFinding;
use App\Models\LibraryScan;
use App\Models\MediaFile;
use App\Models\Upload;
use Illuminate\Database\QueryException;

it('keys library findings by the exact disk and full path without long indexes', function () {
    $scan = LibraryScan::factory()->create();
    $samePath = str_repeat('a', 1023).'x';

    $first = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies_a',
        'relative_path' => $samePath,
    ]);

    expect($first->path_key)->toBe(LibraryFinding::pathKey('movies_a', $samePath))
        ->and(mb_strlen($first->relative_path))->toBe(1024);

    expect(fn () => LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies_a',
        'relative_path' => $samePath,
    ]))->toThrow(QueryException::class);

    $otherDisk = LibraryFinding::factory()->create([
        'library_scan_id' => $scan->id,
        'disk_id' => 'movies_b',
        'relative_path' => $samePath,
    ]);
    $otherScan = LibraryFinding::factory()->create([
        'library_scan_id' => LibraryScan::factory(),
        'disk_id' => 'movies_a',
        'relative_path' => $samePath,
    ]);

    expect($otherDisk->path_key)->not->toBe($first->path_key)
        ->and($otherScan->path_key)->toBe($first->path_key);
});

it('keeps case-different and long-prefix paths distinct under database collation', function () {
    $scan = LibraryScan::factory()->create();
    $prefix = str_repeat('p', 1023);

    $paths = [
        'Movies/Example.mkv',
        'Movies/example.mkv',
        $prefix.'a',
        $prefix.'b',
    ];

    foreach ($paths as $path) {
        LibraryFinding::factory()->create([
            'library_scan_id' => $scan->id,
            'disk_id' => 'movies',
            'relative_path' => $path,
        ]);
    }

    expect(LibraryFinding::query()->whereBelongsTo($scan, 'scan')->count())->toBe(4)
        ->and(LibraryFinding::query()->whereBelongsTo($scan, 'scan')->pluck('path_key')->unique()->count())->toBe(4);
});

it('enforces active media paths by hash while allowing released history', function () {
    $relativePath = implode('/', [
        str_repeat('a', 200),
        str_repeat('b', 200),
        str_repeat('c', 200),
        str_repeat('d', 200),
        str_repeat('e', 216).'.mkv',
    ]);

    expect(mb_strlen($relativePath))->toBe(1024);

    $firstUpload = Upload::factory()->create([
        'disk_id' => 'movies',
        'target_relative_path' => $relativePath,
    ]);
    $first = MediaFile::factory()->forUpload($firstUpload)->create();
    $first->update(['removed_at' => now(), 'removal_reason' => 'replaced']);

    $secondUpload = Upload::factory()->create([
        'disk_id' => 'movies',
        'target_relative_path' => $relativePath,
    ]);
    $second = MediaFile::factory()->forUpload($secondUpload)->create();

    $caseDifferentUpload = Upload::factory()->create([
        'disk_id' => 'movies',
        'target_relative_path' => 'Movies/EXAMPLE.mkv',
    ]);
    $caseDifferent = MediaFile::factory()->forUpload($caseDifferentUpload)->create();

    expect($first->refresh()->active_path_key)->toBeNull()
        ->and($second->active_path_key)->toBe(MediaFile::activePathKey('movies', $relativePath))
        ->and($caseDifferent->active_path_key)->not->toBe($second->active_path_key);
});
