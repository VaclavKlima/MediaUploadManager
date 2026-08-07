<?php

namespace App\Support\Media;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final readonly class LocalTusDevelopmentEnvironment
{
    public const TUSD_VERSION = '2.10.0';

    private const RELEASE_BASE_URL = 'https://github.com/tus/tusd/releases/download/v'.self::TUSD_VERSION;

    public function __construct(private Filesystem $filesystem) {}

    /**
     * @return array{binary_downloaded: bool, herd_configuration_changed: bool, binary_path: string}
     */
    public function prepare(string $herdConfigurationPath, string $applicationHost, string $hookSecret): array
    {
        $this->validateLocalEnvironment($herdConfigurationPath, $applicationHost, $hookSecret);
        $this->ensureRuntimeDirectories();

        $binaryDownloaded = $this->installTusd();
        $runtimeConfigurationChanged = $this->writeRuntimeNginxConfiguration($applicationHost, $hookSecret);
        $herdConfigurationChanged = $this->installHerdIncludes($herdConfigurationPath);

        return [
            'binary_downloaded' => $binaryDownloaded,
            'herd_configuration_changed' => $runtimeConfigurationChanged || $herdConfigurationChanged,
            'binary_path' => $this->binaryPath(),
        ];
    }

    public function defaultHerdConfigurationPath(string $applicationHost): string
    {
        $homeDirectory = $_SERVER['HOME'] ?? null;

        if (! is_string($homeDirectory) || $homeDirectory === '') {
            throw new RuntimeException('The local home directory could not be determined. Use --herd-config.');
        }

        return $homeDirectory.'/Library/Application Support/Herd/config/valet/Nginx/'.$applicationHost;
    }

    /** @return list<string> */
    public function tusdCommand(UploadConfiguration $configuration): array
    {
        return [
            $this->binaryPath(),
            '-host=127.0.0.1',
            '-port=1080',
            '-base-path='.$configuration->tusPublicPath,
            '-behind-proxy',
            '-disable-download',
            '-disable-cors',
            '-upload-dir='.$this->metadataPath(),
            '-hooks-http=http://127.0.0.1:1081/internal/tus/hooks',
            '-hooks-http-timeout=5s',
            '-hooks-http-retry=3',
            '-hooks-http-backoff=1s',
            '-hooks-enabled-events=pre-create,post-create,post-receive,post-finish,pre-terminate,post-terminate',
            '-progress-hooks-interval=5s',
        ];
    }

    private function validateLocalEnvironment(
        string $herdConfigurationPath,
        string $applicationHost,
        string $hookSecret,
    ): void {
        if (PHP_OS_FAMILY !== 'Darwin' || ! in_array(php_uname('m'), ['arm64', 'x86_64'], true)) {
            throw new RuntimeException('The automatic upload setup supports Laravel Herd on macOS only.');
        }

        if (preg_match('/\A[a-z0-9.-]+\.test\z/', $applicationHost) !== 1) {
            throw new RuntimeException('APP_URL must use a valid local HTTPS .test host.');
        }

        if (preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', $hookSecret) !== 1) {
            throw new RuntimeException('TUS_HOOK_SECRET must contain 32-256 letters, numbers, underscores, or hyphens.');
        }

        if (! $this->filesystem->isFile($herdConfigurationPath)) {
            throw new RuntimeException('The secured Herd site configuration was not found.');
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach ([$this->runtimePath(), $this->binaryDirectory(), $this->metadataPath()] as $directory) {
            if (! $this->filesystem->isDirectory($directory)
                && ! $this->filesystem->makeDirectory($directory, 0750, true)
            ) {
                throw new RuntimeException('The local tus runtime directory could not be created.');
            }
        }
    }

    private function installTusd(): bool
    {
        if ($this->filesystem->isFile($this->binaryPath())
            && $this->filesystem->isFile($this->versionPath())
            && trim($this->filesystem->get($this->versionPath())) === self::TUSD_VERSION
        ) {
            return false;
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required to install tusd.');
        }

        $assetName = match (php_uname('m')) {
            'arm64' => 'tusd_darwin_arm64.zip',
            'x86_64' => 'tusd_darwin_amd64.zip',
            default => throw new RuntimeException('This Mac architecture is not supported by the pinned tusd release.'),
        };
        $assetUrl = self::RELEASE_BASE_URL.'/'.$assetName;
        $request = Http::connectTimeout(5)->timeout(120)->retry([500, 1000]);
        $archiveContents = $request->get($assetUrl)->throw()->body();
        $checksumContents = $request->get($assetUrl.'.sha256')->throw()->body();

        if (preg_match('/\b([a-f0-9]{64})\b/i', $checksumContents, $matches) !== 1
            || ! hash_equals(Str::lower($matches[1]), hash('sha256', $archiveContents))
        ) {
            throw new RuntimeException('The downloaded tusd archive failed checksum verification.');
        }

        $temporaryPath = sys_get_temp_dir().'/mum-tusd-'.Str::random(20);

        if (! $this->filesystem->makeDirectory($temporaryPath, 0700, true)) {
            throw new RuntimeException('A temporary tusd installation directory could not be created.');
        }

        try {
            $archivePath = $temporaryPath.'/'.$assetName;
            $this->filesystem->put($archivePath, $archiveContents);
            $zip = new ZipArchive;

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('The downloaded tusd archive could not be opened.');
            }

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entryName = $zip->getNameIndex($index);

                    if (! is_string($entryName)
                        || Str::startsWith($entryName, '/')
                        || Str::contains('/'.$entryName, '/../')
                    ) {
                        throw new RuntimeException('The downloaded tusd archive contains an unsafe path.');
                    }
                }

                if (! $zip->extractTo($temporaryPath.'/extracted')) {
                    throw new RuntimeException('The downloaded tusd archive could not be extracted.');
                }
            } finally {
                $zip->close();
            }

            $sourceBinary = collect($this->filesystem->allFiles($temporaryPath.'/extracted'))
                ->first(fn (\SplFileInfo $file): bool => $file->getFilename() === 'tusd');

            if (! $sourceBinary instanceof \SplFileInfo) {
                throw new RuntimeException('The pinned tusd binary was not present in its release archive.');
            }

            $this->filesystem->put($this->binaryPath(), $this->filesystem->get($sourceBinary->getPathname()));

            if (! chmod($this->binaryPath(), 0750)) {
                throw new RuntimeException('The pinned tusd binary could not be made executable.');
            }

            $this->filesystem->put($this->versionPath(), self::TUSD_VERSION."\n");
        } finally {
            $this->filesystem->deleteDirectory($temporaryPath);
        }

        return true;
    }

    private function writeRuntimeNginxConfiguration(string $applicationHost, string $hookSecret): bool
    {
        $publicTemplate = $this->filesystem->get(base_path('deploy/herd/tus-site.location.conf.example'));
        $hookTemplate = $this->filesystem->get(base_path('deploy/herd/tus-hooks.server.conf.example'));
        $publicConfiguration = Str::replace('media-upload-manager.test', $applicationHost, $publicTemplate);
        $hookConfiguration = Str::replace(
            ['media-upload-manager.test', '<TUS_HOOK_SECRET>'],
            [$applicationHost, $hookSecret],
            $hookTemplate,
        );

        $publicConfigurationChanged = $this->replaceIfChanged($this->publicNginxPath(), $publicConfiguration);
        $hookConfigurationChanged = $this->replaceIfChanged($this->hookNginxPath(), $hookConfiguration);

        return $publicConfigurationChanged || $hookConfigurationChanged;
    }

    private function installHerdIncludes(string $herdConfigurationPath): bool
    {
        $configuration = $this->filesystem->get($herdConfigurationPath);
        $publicMarker = '# MUM_TUS_PUBLIC_INCLUDE';
        $hookMarker = '# MUM_TUS_HOOK_INCLUDE';
        $updatedConfiguration = $configuration;

        if (! Str::contains($updatedConfiguration, $publicMarker)) {
            $httpsListenerPosition = strpos($updatedConfiguration, 'listen 127.0.0.1:443 ssl;');
            $locationPosition = $httpsListenerPosition === false
                ? false
                : strpos($updatedConfiguration, '    location / {', $httpsListenerPosition);

            if ($locationPosition === false) {
                throw new RuntimeException('The secured Herd HTTPS server block could not be identified safely.');
            }

            $include = "    {$publicMarker}\n    include \"{$this->nginxPath($this->publicNginxPath())}\";\n\n";
            $updatedConfiguration = substr($updatedConfiguration, 0, $locationPosition)
                .$include
                .substr($updatedConfiguration, $locationPosition);
        }

        if (! Str::contains($updatedConfiguration, $hookMarker)) {
            $updatedConfiguration = rtrim($updatedConfiguration)
                ."\n\n{$hookMarker}\ninclude \"{$this->nginxPath($this->hookNginxPath())}\";\n";
        }

        if (hash_equals($configuration, $updatedConfiguration)) {
            return false;
        }

        $backupPath = $herdConfigurationPath.'.before-mum-tus';

        if (! $this->filesystem->exists($backupPath)
            && ! $this->filesystem->copy($herdConfigurationPath, $backupPath)
        ) {
            throw new RuntimeException('The Herd site configuration could not be backed up safely.');
        }

        $permissions = fileperms($herdConfigurationPath);
        $this->filesystem->replace($herdConfigurationPath, $updatedConfiguration);

        if (is_int($permissions)) {
            chmod($herdConfigurationPath, $permissions & 0777);
        }

        return true;
    }

    private function replaceIfChanged(string $path, string $contents): bool
    {
        if ($this->filesystem->isFile($path) && hash_equals($this->filesystem->get($path), $contents)) {
            return false;
        }

        $this->filesystem->replace($path, $contents);

        if (! chmod($path, 0600)) {
            throw new RuntimeException('The local tus runtime configuration could not be protected.');
        }

        return true;
    }

    private function nginxPath(string $path): string
    {
        return Str::replace(['\\', '"'], ['\\\\', '\\"'], $path);
    }

    private function runtimePath(): string
    {
        $runtimePath = config('upload.local_tus_runtime_path');

        if (! is_string($runtimePath) || ! str_starts_with($runtimePath, '/')) {
            throw new RuntimeException('The local tus runtime path is invalid.');
        }

        return rtrim($runtimePath, '/');
    }

    private function binaryDirectory(): string
    {
        return $this->runtimePath().'/bin';
    }

    private function binaryPath(): string
    {
        return $this->binaryDirectory().'/tusd';
    }

    private function versionPath(): string
    {
        return $this->binaryDirectory().'/version';
    }

    private function metadataPath(): string
    {
        return $this->runtimePath().'/metadata';
    }

    private function publicNginxPath(): string
    {
        return $this->runtimePath().'/herd-public.conf';
    }

    private function hookNginxPath(): string
    {
        return $this->runtimePath().'/herd-hooks.conf';
    }
}
