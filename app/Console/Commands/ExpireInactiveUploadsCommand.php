<?php

namespace App\Console\Commands;

use App\Support\Media\ExpireInactiveUploads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:expire-inactive')]
#[Description('Safely expire inactive resumable upload sessions')]
class ExpireInactiveUploadsCommand extends Command
{
    public function handle(ExpireInactiveUploads $expiry): int
    {
        $summary = $expiry->execute();

        $this->components->info(sprintf(
            'Examined %d due uploads: %d expired, %d refreshed, %d moved to processing, %d termination requests, %d deferred.',
            $summary['examined'],
            $summary['expired'],
            $summary['refreshed'],
            $summary['processing'],
            $summary['termination_requested'],
            $summary['deferred'],
        ));

        return self::SUCCESS;
    }
}
