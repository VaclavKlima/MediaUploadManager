<?php

use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\NativeMediaFilesystem;
use Illuminate\Filesystem\Filesystem;

function validMediaConfiguration(array $overrides = []): array
{
    $configuration = [
        'disks' => [[
            'id' => 'movies_a',
            'label' => 'Movies A',
            'path' => '/mnt/media/movies-a',
            'reserve_gib' => null,
        ]],
        'default_reserve_gib' => '20',
        'require_mountpoint' => false,
    ];

    foreach ($overrides as $key => $value) {
        $configuration[$key] = $value;
    }

    return $configuration;
}

it('parses normalized disks and converts reserves to bytes safely', function () {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
        'disks' => [
            [
                'id' => 'movies_a',
                'label' => '  Movies A  ',
                'path' => '/mnt/media/movies-a/',
                'reserve_gib' => null,
            ],
            [
                'id' => 'movies_b',
                'label' => 'Movies B',
                'path' => '/mnt/media/movies-b',
                'reserve_gib' => '2',
            ],
        ],
        'require_mountpoint' => 'true',
    ]), new NativeMediaFilesystem, false);

    expect($registry->all())
        ->toHaveCount(2)
        ->and($registry->all()[0]->label)->toBe('Movies A')
        ->and($registry->all()[0]->root)->toBe('/mnt/media/movies-a')
        ->and($registry->all()[0]->safetyReserveBytes)->toBe(21_474_836_480)
        ->and($registry->all()[1]->safetyReserveBytes)->toBe(2_147_483_648)
        ->and($registry->requiresMountpoint())->toBeTrue();
});

it('permits no configured disks outside production', function () {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration(['disks' => []]), new NativeMediaFilesystem, false);

    expect($registry->all())->toBe([]);
});

it('requires at least one configured disk in production', function () {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration(['disks' => []]), new NativeMediaFilesystem, true);

    expect(fn () => $registry->all())->toThrow(MediaConfigurationException::class);
});

it('rejects invalid static disk configuration', function (array $overrides) {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration($overrides), new NativeMediaFilesystem, false);

    expect(fn () => $registry->all())->toThrow(MediaConfigurationException::class);
})->with([
    'uppercase ID' => [['disks' => [['id' => 'Movies', 'label' => 'Movies', 'path' => '/mnt/movies']]]],
    'leading number ID' => [['disks' => [['id' => '1movies', 'label' => 'Movies', 'path' => '/mnt/movies']]]],
    'empty label' => [['disks' => [['id' => 'movies', 'label' => '  ', 'path' => '/mnt/movies']]]],
    'label control character' => [['disks' => [['id' => 'movies', 'label' => "Movies\nInjected", 'path' => '/mnt/movies']]]],
    'relative path' => [['disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => 'mnt/movies']]]],
    'filesystem root' => [['disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => '/']]]],
    'path traversal' => [['disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => '/mnt/../movies']]]],
    'path control character' => [['disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => "/mnt/movies\nother"]]]],
    'negative reserve' => [['disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => '/mnt/movies', 'reserve_gib' => '-1']]]],
    'fractional reserve' => [['disks' => [['id' => 'movies', 'label' => 'Movies', 'path' => '/mnt/movies', 'reserve_gib' => '1.5']]]],
    'invalid mountpoint flag' => [['require_mountpoint' => 'sometimes']],
]);

it('rejects duplicate IDs labels and configured paths', function (string $field, mixed $duplicate) {
    $first = ['id' => 'movies_a', 'label' => 'Movies A', 'path' => '/mnt/a'];
    $second = ['id' => 'movies_b', 'label' => 'Movies B', 'path' => '/mnt/b'];
    $second[$field] = $duplicate;
    $registry = new ConfiguredDiskRegistry(
        validMediaConfiguration(['disks' => [$first, $second]]),
        new NativeMediaFilesystem,
        false,
    );

    expect(fn () => $registry->all())->toThrow(MediaConfigurationException::class);
})->with([
    'ID' => ['id', 'movies_a'],
    'label' => ['label', 'Movies A'],
    'path' => ['path', '/mnt/a'],
]);

it('rejects reserve conversion overflow', function () {
    $overflowingGib = (string) (intdiv(PHP_INT_MAX, 1_073_741_824) + 1);
    $registry = new ConfiguredDiskRegistry(
        validMediaConfiguration(['default_reserve_gib' => $overflowingGib]),
        new NativeMediaFilesystem,
        false,
    );

    expect(fn () => $registry->all())->toThrow(MediaConfigurationException::class);
});

it('rejects roots that resolve to the same location', function () {
    $filesystem = new Filesystem;
    $base = getcwd().'/storage/framework/testing/registry-'.bin2hex(random_bytes(6));
    $realRoot = $base.'/real';
    $aliasRoot = $base.'/alias';
    $filesystem->makeDirectory($realRoot, 0750, true);

    try {
        expect(symlink($realRoot, $aliasRoot))->toBeTrue();
        $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
            'disks' => [
                ['id' => 'movies_a', 'label' => 'Movies A', 'path' => $realRoot],
                ['id' => 'movies_b', 'label' => 'Movies B', 'path' => $aliasRoot],
            ],
        ]), new NativeMediaFilesystem, false);

        expect(fn () => $registry->all())->toThrow(MediaConfigurationException::class);
    } finally {
        $filesystem->deleteDirectory($base);
    }
});
