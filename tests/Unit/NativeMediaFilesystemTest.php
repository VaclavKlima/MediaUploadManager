<?php

use App\Support\Media\Exceptions\HardLinkCreationException;
use App\Support\Media\NativeMediaFilesystem;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->root = sys_get_temp_dir().'/native-media-filesystem-'.bin2hex(random_bytes(6));
    $this->filesystem->makeDirectory($this->root, 0750, true);
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->root);
});

it('converts native hard-link permission failures to a path-free typed exception', function (string $nativeFailure) {
    $source = $this->root.'/private-source.mkv';
    $target = $this->root.'/private-target.mkv';
    file_put_contents($source, 'movie-bytes');
    $filesystem = new class($nativeFailure) extends NativeMediaFilesystem
    {
        public function __construct(private readonly string $nativeFailure) {}

        protected function createNativeHardLink(string $source, string $target): bool
        {
            trigger_error("link($source, $target): {$this->nativeFailure}", E_USER_WARNING);

            return false;
        }
    };
    $exception = null;

    try {
        $filesystem->createHardLinkExclusively($source, $target);
    } catch (HardLinkCreationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(HardLinkCreationException::class)
        ->and($exception?->reason)->toBe('permission_denied')
        ->and($exception?->getMessage())->toBe('Hard-link creation was denied by the media filesystem.')
        ->not->toContain($source, $target, $nativeFailure)
        ->and($source)->toBeFile()
        ->and($target)->not->toBeFile();
})->with([
    'permission denied' => 'Permission denied',
    'operation prohibited' => 'Operation not permitted',
]);

it('leaves an existing target untouched and reports an exclusive-link conflict', function () {
    $source = $this->root.'/source.mkv';
    $target = $this->root.'/target.mkv';
    file_put_contents($source, 'source-bytes');
    file_put_contents($target, 'existing-target-bytes');
    $sourceInode = lstat($source)['ino'];
    $targetInode = lstat($target)['ino'];

    $created = (new NativeMediaFilesystem)->createHardLinkExclusively($source, $target);

    expect($created)->toBeFalse()
        ->and(file_get_contents($source))->toBe('source-bytes')
        ->and(file_get_contents($target))->toBe('existing-target-bytes')
        ->and(lstat($source)['ino'])->toBe($sourceInode)
        ->and(lstat($target)['ino'])->toBe($targetInode);
});

it('probes write flush rename exclusive hard-link inode and unlink behavior without artifacts', function () {
    $filesystem = new NativeMediaFilesystem;

    expect($filesystem->probe($this->root))->toBeTrue()
        ->and(glob($this->root.'/.health-*') ?: [])->toBe([]);
});

it('fails a probe when exclusive hard links are unavailable and cleans every artifact', function () {
    $filesystem = new class extends NativeMediaFilesystem
    {
        public function createHardLinkExclusively(string $source, string $target): bool
        {
            return false;
        }
    };

    expect($filesystem->probe($this->root))->toBeFalse()
        ->and(glob($this->root.'/.health-*') ?: [])->toBe([]);
});
