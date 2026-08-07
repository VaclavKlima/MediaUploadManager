<?php

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Jobs\ProcessCompletedUpload;
use App\Models\Upload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:recover-processing')]
#[Description('Queue idempotent finalization for every upload left in processing')]
class RecoverProcessingUploadsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        Upload::query()
            ->where('status', UploadStatus::Processing)
            ->select('id')
            ->chunkById(100, function ($uploads) use (&$queued): void {
                foreach ($uploads as $upload) {
                    ProcessCompletedUpload::dispatch($upload->id);
                    $queued++;
                }
            });

        $this->components->info("Queued {$queued} processing upload(s) for recovery.");

        return self::SUCCESS;
    }
}
