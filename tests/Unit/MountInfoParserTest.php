<?php

use App\Support\Media\Contracts\MountInfoSource;
use App\Support\Media\Contracts\OperatingSystem;
use App\Support\Media\LinuxMountInspector;
use App\Support\Media\MountInfoParser;

it('parses exact mount points including bind mounts and escaped fields', function () {
    $mountInfo = <<<'MOUNTS'
24 18 0:21 / / rw,relatime - overlay overlay rw
31 24 0:45 /movies /mnt/media/Movies\040A rw,relatime - nfs nas:/media rw
32 24 8:2 /library /mnt/media/bind rw,relatime shared:7 - ext4 /dev/sda2 rw
MOUNTS;

    expect((new MountInfoParser)->mountPoints($mountInfo))->toBe([
        '/',
        '/mnt/media/Movies A',
        '/mnt/media/bind',
    ]);
});

it('ignores malformed mountinfo records without creating a false match', function () {
    $mountInfo = "malformed\n24 18 0:21 / /mnt/bad\\999 rw - ext4 /dev/sda rw\n";

    expect((new MountInfoParser)->mountPoints($mountInfo))->toBe([]);
});

it('requires an exact mountpoint match on Linux', function () {
    $operatingSystem = new class implements OperatingSystem
    {
        public function isLinux(): bool
        {
            return true;
        }
    };
    $source = new class implements MountInfoSource
    {
        public function read(): ?string
        {
            return '31 24 0:45 / /mnt/media rw,relatime - nfs nas:/media rw';
        }
    };
    $inspector = new LinuxMountInspector($operatingSystem, $source, new MountInfoParser);

    expect($inspector->inspect('/mnt/media')->available)->toBeTrue()
        ->and($inspector->inspect('/mnt/media')->exactMountPoint)->toBeTrue()
        ->and($inspector->inspect('/mnt/media/movies')->exactMountPoint)->toBeFalse();
});

it('fails closed when proc mount information is unavailable', function () {
    $operatingSystem = new class implements OperatingSystem
    {
        public function isLinux(): bool
        {
            return true;
        }
    };
    $source = new class implements MountInfoSource
    {
        public function read(): ?string
        {
            return null;
        }
    };

    expect((new LinuxMountInspector($operatingSystem, $source, new MountInfoParser))->inspect('/mnt/media')->available)
        ->toBeFalse();
});

it('does not treat ordinary macOS directories as inspectable Linux mounts', function () {
    $operatingSystem = new class implements OperatingSystem
    {
        public function isLinux(): bool
        {
            return false;
        }
    };
    $source = new class implements MountInfoSource
    {
        public function read(): ?string
        {
            return '31 24 0:45 / /mnt/media rw,relatime - nfs nas:/media rw';
        }
    };

    expect((new LinuxMountInspector($operatingSystem, $source, new MountInfoParser))->inspect('/mnt/media')->available)
        ->toBeFalse();
});
