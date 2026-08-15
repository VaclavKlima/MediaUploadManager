<?php

namespace App\Console\Commands;

use App\Enums\MediaRootKind;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\ConfiguredMediaDisk;
use App\Support\Media\DiskHealthStatus;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\MediaDiskHealthChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('media:disks:check
    {--kind= : Check only one root kind (movies or series)}
    {--json : Emit a safe machine-readable response}')]
#[Description('Check configured media disks and report health and capacity')]
class CheckMediaDisksCommand extends Command
{
    /**
     * @throws JsonException
     */
    public function handle(
        ConfiguredDiskRegistry $diskRegistry,
        MediaDiskHealthChecker $healthChecker,
    ): int {
        $kindOption = $this->option('kind');
        $kind = is_string($kindOption) && $kindOption !== ''
            ? MediaRootKind::tryFrom($kindOption)
            : null;

        if (is_string($kindOption) && $kindOption !== '' && $kind === null) {
            $this->configurationError('invalid_kind', 'The root kind must be either movies or series.');

            return self::INVALID;
        }

        try {
            $configuredDisks = $kind === null
                ? $diskRegistry->allRoots()
                : $diskRegistry->forKind($kind);
        } catch (MediaConfigurationException $exception) {
            if ($this->option('json')) {
                $this->configurationError('invalid_configuration', 'Media disk configuration is invalid.');
            } else {
                $this->components->error($exception->getMessage());

                foreach ($exception->errors as $error) {
                    $this->line('  - '.$error);
                }
            }

            return self::INVALID;
        }

        $statuses = array_map(
            fn (ConfiguredMediaDisk $disk): DiskHealthStatus => $healthChecker->check(
                $disk,
                $diskRegistry->requiresMountpoint(),
            ),
            $configuredDisks,
        );

        if ($this->option('json')) {
            $this->line(json_encode([
                'data' => array_map(
                    fn (DiskHealthStatus $status): array => $status->toRootArray(),
                    $statuses,
                ),
            ], JSON_THROW_ON_ERROR));
        } else {
            foreach ($configuredDisks as $index => $disk) {
                $status = $statuses[$index];
                $this->components->twoColumnDetail(
                    $disk->label.' ['.$disk->id.' / '.$disk->kind->value.']',
                    $status->healthy ? 'healthy' : 'unhealthy',
                );
                $this->components->twoColumnDetail('Root', $disk->root);

                foreach ($status->reasons as $reason) {
                    $this->line('  - '.$reason->message());
                }
            }

            if ($configuredDisks === []) {
                $this->components->info('No media disks are configured.');
            }
        }

        foreach ($statuses as $status) {
            if (! $status->healthy) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @throws JsonException */
    private function configurationError(string $code, string $message): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return;
        }

        $this->components->error($message);
    }
}
