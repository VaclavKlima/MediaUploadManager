<?php

namespace App\Support\Media;

use App\Support\Media\Contracts\MountInfoSource;
use App\Support\Media\Contracts\MountPointChecker;
use App\Support\Media\Contracts\OperatingSystem;

final readonly class LinuxMountInspector implements MountPointChecker
{
    public function __construct(
        private OperatingSystem $operatingSystem,
        private MountInfoSource $mountInfoSource,
        private MountInfoParser $parser,
    ) {}

    public function inspect(string $resolvedRoot): MountPointInspection
    {
        if (! $this->operatingSystem->isLinux()) {
            return MountPointInspection::unavailable();
        }

        $mountInfo = $this->mountInfoSource->read();

        if ($mountInfo === null) {
            return MountPointInspection::unavailable();
        }

        return MountPointInspection::detected(
            in_array($resolvedRoot, $this->parser->mountPoints($mountInfo), true),
        );
    }
}
