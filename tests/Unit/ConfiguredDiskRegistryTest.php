<?php

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
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

it('supports legacy aliases, explicit roots, and deterministic root ordering', function () {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
        'disks' => [
            [
                'id' => 'nas_a',
                'label' => 'NAS A',
                'path' => '/mnt/a/movies',
                'movies_path' => '/mnt/a/movies',
                'series_path' => '/mnt/a/series',
                'series_default_category' => 'tv',
                'reserve_gib' => '3',
            ],
            [
                'id' => 'nas_b',
                'label' => 'NAS B',
                'series_path' => '/mnt/b/series',
                'series_default_category' => 'anime',
                'reserve_gib' => '4',
            ],
        ],
    ]), new NativeMediaFilesystem, false);

    expect(array_map(
        fn (ConfiguredMediaDisk $root): array => [$root->id, $root->kind, $root->root],
        $registry->allRoots(),
    ))->toBe([
        ['nas_a', MediaRootKind::Movies, '/mnt/a/movies'],
        ['nas_a', MediaRootKind::Series, '/mnt/a/series'],
        ['nas_b', MediaRootKind::Series, '/mnt/b/series'],
    ])->and($registry->all())->toHaveCount(1)
        ->and($registry->find('nas_b'))->toBeNull()
        ->and($registry->forKind(MediaRootKind::Series))->toHaveCount(2)
        ->and($registry->findRoot('nas_a', MediaRootKind::Series)?->seriesDefaultCategory)->toBe(SeriesCategory::Tv)
        ->and($registry->findRoot('nas_b', MediaRootKind::Series)?->seriesDefaultCategory)->toBe(SeriesCategory::Anime)
        ->and($registry->findRoot('nas_b', MediaRootKind::Series)?->safetyReserveBytes)->toBe(4_294_967_296)
        ->and($registry->findRoot('nas_a', MediaRootKind::Movies)?->seriesDefaultCategory)->toBeNull();
});

it('keeps Series imports manual when the default category is unset or blank', function (mixed $category) {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
        'disks' => [[
            'id' => 'series',
            'label' => 'Series',
            'series_path' => '/mnt/series',
            'series_default_category' => $category,
        ]],
    ]), new NativeMediaFilesystem, false);

    expect($registry->findRoot('series', MediaRootKind::Series)?->seriesDefaultCategory)->toBeNull();
})->with([null, '']);

it('permits a Series-only disk in production while keeping Movie APIs empty', function () {
    $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
        'disks' => [[
            'id' => 'nas_a',
            'label' => 'NAS A',
            'series_path' => '/mnt/nas-a-series',
        ]],
    ]), new NativeMediaFilesystem, true);

    expect($registry->all())->toBe([])
        ->and($registry->allRoots())->toHaveCount(1)
        ->and($registry->allRoots()[0]->kind)->toBe(MediaRootKind::Series);
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
    'no root' => [['disks' => [['id' => 'movies', 'label' => 'Movies']]]],
    'legacy and explicit Movie mismatch' => [['disks' => [[
        'id' => 'movies',
        'label' => 'Movies',
        'path' => '/mnt/movies-a',
        'movies_path' => '/mnt/movies-b',
    ]]]],
    'Movie and Series duplicate' => [['disks' => [[
        'id' => 'media',
        'label' => 'Media',
        'movies_path' => '/mnt/media',
        'series_path' => '/mnt/media',
    ]]]],
    'nested kinds' => [['disks' => [[
        'id' => 'media',
        'label' => 'Media',
        'movies_path' => '/mnt/media',
        'series_path' => '/mnt/media/series',
    ]]]],
    'invalid Series default category' => [['disks' => [[
        'id' => 'media',
        'label' => 'Media',
        'series_path' => '/mnt/series',
        'series_default_category' => 'documentary',
    ]]]],
    'Series default category without Series root' => [['disks' => [[
        'id' => 'media',
        'label' => 'Media',
        'movies_path' => '/mnt/movies',
        'series_default_category' => 'tv',
    ]]]],
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

it('accepts legacy and explicit Movie aliases that resolve to the same root', function () {
    $filesystem = new Filesystem;
    $base = getcwd().'/storage/framework/testing/registry-alias-'.bin2hex(random_bytes(6));
    $realRoot = $base.'/real';
    $aliasRoot = $base.'/alias';
    $filesystem->makeDirectory($realRoot, 0750, true);

    try {
        expect(symlink($realRoot, $aliasRoot))->toBeTrue();
        $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
            'disks' => [[
                'id' => 'movies',
                'label' => 'Movies',
                'path' => $aliasRoot,
                'movies_path' => $realRoot,
            ]],
        ]), new NativeMediaFilesystem, false);

        expect($registry->all())->toHaveCount(1)
            ->and($registry->all()[0]->root)->toBe($realRoot);
    } finally {
        $filesystem->deleteDirectory($base);
    }
});

it('rejects Movie and Series roots on different available filesystems', function () {
    $directoryFilesystem = new Filesystem;
    $base = getcwd().'/storage/framework/testing/registry-devices-'.bin2hex(random_bytes(6));
    $movieRoot = $base.'/movies';
    $seriesRoot = $base.'/series';
    $directoryFilesystem->makeDirectory($movieRoot, 0750, true);
    $directoryFilesystem->makeDirectory($seriesRoot, 0750, true);
    $filesystem = new class extends NativeMediaFilesystem
    {
        public function deviceId(string $path): ?int
        {
            return str_contains($path, 'series') ? 22 : 11;
        }
    };
    try {
        $registry = new ConfiguredDiskRegistry(validMediaConfiguration([
            'disks' => [[
                'id' => 'media',
                'label' => 'Media',
                'movies_path' => $movieRoot,
                'series_path' => $seriesRoot,
            ]],
        ]), $filesystem, false);

        expect(fn () => $registry->allRoots())->toThrow(MediaConfigurationException::class);
    } finally {
        $directoryFilesystem->deleteDirectory($base);
    }
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
