<?php

namespace App\Console\Commands;

use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\LocalTusDevelopmentEnvironment;
use App\Support\Media\UploadConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

use function Laravel\Prompts\confirm;

#[Signature('upload:dev
    {--prepare-only : Prepare tusd and Herd without starting the long-running transport}
    {--run-only : Start an already prepared tusd under the development process supervisor}
    {--force : Apply local disk and Herd setup without confirmation}
    {--herd-config= : Override the secured Herd site configuration path}')]
#[Description('Prepare and run the protected local tus upload transport through Laravel Herd')]
class StartLocalTusDevelopmentCommand extends Command
{
    public function handle(
        ConfiguredDiskRegistry $diskRegistry,
        LocalTusDevelopmentEnvironment $environment,
    ): int {
        if (app()->isProduction()) {
            $this->components->error('The local upload transport command may run only in the local environment.');

            return self::INVALID;
        }

        $uploadConfiguration = app(UploadConfiguration::class);

        if (! $this->ffprobeAvailable($uploadConfiguration)) {
            $this->components->error('FFPROBE_BINARY is unavailable. Install it with `brew install ffmpeg` and set the absolute binary path.');

            return self::INVALID;
        }

        try {
            $disks = $diskRegistry->allRoots();

            if ($this->option('run-only')) {
                if ($this->call('uploads:recover-processing') !== self::SUCCESS) {
                    return self::FAILURE;
                }

                return $this->runTusd($environment, $uploadConfiguration);
            }

            $applicationHost = $this->applicationHost();
            $hookSecret = $this->hookSecret();
            $herdConfigurationPath = $this->herdConfigurationPath($environment, $applicationHost);
        } catch (MediaConfigurationException|\RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirmPreparation();

            if ($confirmed === null) {
                return self::INVALID;
            }

            if (! $confirmed) {
                return self::SUCCESS;
            }
        }

        foreach ($disks as $disk) {
            if ($this->call('media:disks:initialize', [
                'disk' => $disk->id,
                '--kind' => $disk->kind->value,
                '--no-interaction' => true,
            ]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if ($this->call('media:disks:check') !== self::SUCCESS) {
            return self::FAILURE;
        }

        try {
            $preparation = $environment->prepare(
                $herdConfigurationPath,
                $applicationHost,
                $hookSecret,
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($preparation['binary_downloaded']) {
            $this->components->info('Installed pinned tusd '.LocalTusDevelopmentEnvironment::TUSD_VERSION.'.');
        }

        if ($preparation['herd_configuration_changed']) {
            $restart = Process::timeout(30)->run(['herd', 'restart']);

            if ($restart->failed()) {
                $this->components->error('Herd could not be restarted after installing the upload proxy.');

                return self::FAILURE;
            }

            $this->components->info('Installed the protected Herd upload proxy and restarted Herd.');
        }

        if ($this->option('prepare-only')) {
            $this->components->success('The local upload transport is prepared.');

            return self::SUCCESS;
        }

        if ($this->call('uploads:recover-processing') !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->runTusd($environment, $uploadConfiguration);
    }

    private function runTusd(
        LocalTusDevelopmentEnvironment $environment,
        UploadConfiguration $uploadConfiguration,
    ): int {
        $this->components->info('Starting tusd on 127.0.0.1:1080. Press Ctrl+C to stop it.');
        $result = Process::forever()->run(
            $environment->tusdCommand($uploadConfiguration),
            function (string $type, string $output): void {
                $this->output->write($output);
            },
        );

        if ($result->failed()) {
            $this->components->error('The local tus upload transport stopped with an error.');
        }

        return $result->exitCode() ?? self::FAILURE;
    }

    private function ffprobeAvailable(UploadConfiguration $configuration): bool
    {
        try {
            return Process::timeout(5)
                ->run([$configuration->ffprobeBinary, '-version'])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function confirmPreparation(): ?bool
    {
        if (! $this->input->isInteractive() || ! defined('STDIN') || ! stream_isatty(STDIN)) {
            $this->components->error('Non-interactive setup requires the explicit --force option.');

            return null;
        }

        return confirm(
            label: 'Initialize configured media disks and install the local Herd upload transport?',
            default: true,
            hint: 'Only private media metadata/incoming paths and a backed-up Herd site configuration are changed.',
        );
    }

    private function applicationHost(): string
    {
        $applicationUrl = config('app.url');
        $applicationHost = is_string($applicationUrl) ? parse_url($applicationUrl, PHP_URL_HOST) : null;
        $scheme = is_string($applicationUrl) ? parse_url($applicationUrl, PHP_URL_SCHEME) : null;

        if (! is_string($applicationHost) || $applicationHost === '' || $scheme !== 'https') {
            throw new \RuntimeException('APP_URL must be the secured local Herd URL.');
        }

        return $applicationHost;
    }

    private function hookSecret(): string
    {
        $hookSecret = config('upload.hook_secret');

        if (! is_string($hookSecret) || $hookSecret === '') {
            throw new \RuntimeException('TUS_HOOK_SECRET is missing from the loaded configuration.');
        }

        return $hookSecret;
    }

    private function herdConfigurationPath(
        LocalTusDevelopmentEnvironment $environment,
        string $applicationHost,
    ): string {
        $override = $this->option('herd-config');

        return is_string($override) && $override !== ''
            ? $override
            : $environment->defaultHerdConfigurationPath($applicationHost);
    }
}
