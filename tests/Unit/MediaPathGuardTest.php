<?php

use App\Support\Media\Exceptions\MediaPathException;
use App\Support\Media\MediaPathGuard;
use App\Support\Media\NativeMediaFilesystem;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->root = getcwd().'/storage/framework/testing/path-guard-'.bin2hex(random_bytes(6));
    $this->outside = getcwd().'/storage/framework/testing/path-outside-'.bin2hex(random_bytes(6));
    $this->filesystem->makeDirectory($this->root, 0750, true);
    $this->filesystem->makeDirectory($this->outside, 0750, true);
    $this->guard = new MediaPathGuard(new NativeMediaFilesystem);
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->root);
    $this->filesystem->deleteDirectory($this->outside);
});

it('resolves safe existing and future nested paths beneath the root', function () {
    $this->filesystem->makeDirectory($this->root.'/existing', 0750);

    expect($this->guard->resolveChild($this->root, 'existing/future/movie.mkv'))
        ->toBe($this->root.'/existing/future/movie.mkv');
});

it('rejects unsafe relative child paths', function (string $path) {
    expect(fn () => $this->guard->resolveChild($this->root, $path))
        ->toThrow(MediaPathException::class);
})->with([
    'absolute' => '/etc/passwd',
    'traversal' => '../outside',
    'nested traversal' => 'incoming/../../outside',
    'current segment' => 'incoming/./file',
    'empty segment' => 'incoming//file',
    'NUL byte' => "incoming/evil\0file",
    'backslash separator' => 'incoming\\file',
    'control byte' => "incoming/evil\nfile",
]);

it('rejects prefix collision traversal attempts', function () {
    expect(fn () => $this->guard->resolveChild($this->root, '../'.basename($this->root).'-other/file'))
        ->toThrow(MediaPathException::class);
});

it('rejects symlinks in existing child ancestors even when the final path is future', function () {
    expect(symlink($this->outside, $this->root.'/linked'))->toBeTrue();

    expect(fn () => $this->guard->resolveChild($this->root, 'linked/future/movie.mkv'))
        ->toThrow(MediaPathException::class);
});

it('rejects a configured root with a symlinked ancestor', function () {
    $alias = getcwd().'/storage/framework/testing/path-alias-'.bin2hex(random_bytes(6));
    expect(symlink($this->root, $alias))->toBeTrue();

    try {
        expect(fn () => $this->guard->resolveRoot($alias))->toThrow(MediaPathException::class);
    } finally {
        unlink($alias);
    }
});

it('does not create a missing configured root', function () {
    $missing = $this->root.'/missing';

    expect(fn () => $this->guard->resolveRoot($missing))->toThrow(MediaPathException::class)
        ->and(file_exists($missing))->toBeFalse();
});
