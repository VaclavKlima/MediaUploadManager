<?php

namespace App\Console\Commands;

use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Exceptions\DiskInitializationException;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\MediaDiskInitializer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

#[Signature('media:disks:initialize {disk : Stable ID of the configured disk}')]
#[Description('Initialize the private metadata and incoming tree on a media disk')]
class InitializeMediaDiskCommand extends Command
{
    public function handle(
        ConfiguredDiskRegistry $diskRegistry,
        MediaDiskInitializer $initializer,
    ): int {
        try {
            $disk = $diskRegistry->find((string) $this->argument('disk'));
        } catch (MediaConfigurationException $exception) {
            $this->components->error($exception->getMessage());

            foreach ($exception->errors as $error) {
                $this->line('  - '.$error);
            }

            return self::INVALID;
        }

        if ($disk === null) {
            $this->components->error('The requested media disk is not configured.');

            return self::INVALID;
        }

        $this->line('Label: '.$disk->label);
        $this->line('Root: '.$disk->root);

        if ($this->isInteractive() && ! confirm(
            label: 'Initialize this media disk?',
            default: false,
            hint: 'Only .media-upload-manager metadata and incoming paths will be created.',
        )) {
            warning('Media disk initialization cancelled. No paths were created.');

            return self::SUCCESS;
        }

        try {
            $created = $initializer->initialize($disk, $diskRegistry->requiresMountpoint());
        } catch (DiskInitializationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($created) {
            $this->components->success('Media disk initialized.');
        } else {
            $this->components->info('Media disk is already initialized with a matching marker.');
        }

        return self::SUCCESS;
    }

    private function isInteractive(): bool
    {
        return $this->input->isInteractive()
            && (app()->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }
}
