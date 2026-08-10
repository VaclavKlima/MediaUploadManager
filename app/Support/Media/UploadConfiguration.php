<?php

namespace App\Support\Media;

use App\Support\Media\Exceptions\UploadAdmissionException;

class UploadConfiguration
{
    public readonly string $tusPublicPath;

    public readonly string $tusInternalUrl;

    public readonly int $chunkSizeBytes;

    /** @var list<int> */
    public readonly array $retryDelaysMilliseconds;

    public readonly int $internalConnectTimeoutSeconds;

    public readonly int $internalTimeoutSeconds;

    public readonly int $tokenTtlSeconds;

    public readonly int $tokenRefreshLeewaySeconds;

    public readonly int $inactivitySeconds;

    public readonly int $fingerprintWindowBytes;

    public readonly string $tusMetadataPath;

    public readonly string $ffprobeBinary;

    public readonly int $ffprobeTimeoutSeconds;

    public readonly int $ffprobeMaxOutputBytes;

    public readonly int $ffprobeMaxStreams;

    public readonly int $processingJobTimeoutSeconds;

    public readonly int $processingJobUniqueSeconds;

    /** @var list<int> */
    public readonly array $processingJobBackoffSeconds;

    public readonly int $processingPollIntervalMilliseconds;

    public readonly int $queueRetryAfterSeconds;

    private readonly string $hookSecret;

    /** @param array<mixed> $configuration */
    public function __construct(array $configuration, bool $production = false)
    {
        $tusPublicPath = $configuration['tus_public_path'] ?? null;
        $tusInternalUrl = $configuration['tus_internal_url'] ?? null;
        $hookSecret = $configuration['hook_secret'] ?? null;
        $chunkSizeBytes = $this->positiveInteger($configuration['chunk_size_bytes'] ?? null);
        $retryDelaysMilliseconds = $this->retryDelays($configuration['retry_delays_milliseconds'] ?? null);
        $internalConnectTimeoutSeconds = $this->positiveInteger($configuration['internal_connect_timeout_seconds'] ?? null);
        $internalTimeoutSeconds = $this->positiveInteger($configuration['internal_timeout_seconds'] ?? null);
        $tokenTtlSeconds = $this->positiveInteger($configuration['token_ttl_seconds'] ?? null);
        $tokenRefreshLeewaySeconds = $this->positiveInteger($configuration['token_refresh_leeway_seconds'] ?? null);
        $inactivitySeconds = $this->positiveInteger($configuration['inactivity_seconds'] ?? null);
        $fingerprintWindowBytes = $this->positiveInteger($configuration['fingerprint_window_bytes'] ?? null);
        $tusMetadataPath = $this->absolutePath($configuration['tus_metadata_path'] ?? null);
        $ffprobeBinary = $this->binary($configuration['ffprobe_binary'] ?? null, $production);
        $ffprobeTimeoutSeconds = $this->positiveInteger($configuration['ffprobe_timeout_seconds'] ?? null);
        $ffprobeMaxOutputBytes = $this->positiveInteger($configuration['ffprobe_max_output_bytes'] ?? null);
        $ffprobeMaxStreams = $this->positiveInteger($configuration['ffprobe_max_streams'] ?? null);
        $processingJobTimeoutSeconds = $this->positiveInteger($configuration['processing_job_timeout_seconds'] ?? null);
        $processingJobUniqueSeconds = $this->positiveInteger($configuration['processing_job_unique_seconds'] ?? null);
        $processingJobBackoffSeconds = $this->retryDelays($configuration['processing_job_backoff_seconds'] ?? null);
        $processingPollIntervalMilliseconds = $this->positiveInteger($configuration['processing_poll_interval_milliseconds'] ?? null);
        $queueRetryAfterSeconds = $this->positiveInteger($configuration['queue_retry_after_seconds'] ?? null);

        if (! $production) {
            $tusInternalUrl ??= 'http://127.0.0.1:1080/uploads/tus/';
            $hookSecret ??= str_repeat('local-development-only-', 2);
            $chunkSizeBytes ??= 67_108_864;
            $retryDelaysMilliseconds ??= [0, 3000, 5000, 10000, 20000];
            $internalConnectTimeoutSeconds ??= 2;
            $internalTimeoutSeconds ??= 5;
            $tokenRefreshLeewaySeconds ??= 60;
            $tusMetadataPath ??= storage_path('app/tusd/metadata');
            $ffprobeBinary ??= 'ffprobe';
            $ffprobeTimeoutSeconds ??= 120;
            $ffprobeMaxOutputBytes ??= 1_048_576;
            $ffprobeMaxStreams ??= 64;
            $processingJobTimeoutSeconds ??= 180;
            $processingJobUniqueSeconds ??= 3600;
            $processingJobBackoffSeconds ??= [15, 60, 180];
            $processingPollIntervalMilliseconds ??= 1500;
            $queueRetryAfterSeconds ??= 240;
        }

        if (! is_string($tusPublicPath)
            || ! $this->validPublicPath($tusPublicPath)
            || ! is_string($tusInternalUrl)
            || ! $this->validInternalUrl($tusInternalUrl)
            || ! is_string($hookSecret)
            || strlen($hookSecret) < 32
            || $chunkSizeBytes === null
            || $retryDelaysMilliseconds === null
            || $internalConnectTimeoutSeconds === null
            || $internalTimeoutSeconds === null
            || $internalConnectTimeoutSeconds > $internalTimeoutSeconds
            || $tokenTtlSeconds === null
            || $tokenRefreshLeewaySeconds === null
            || $tokenRefreshLeewaySeconds >= $tokenTtlSeconds
            || $inactivitySeconds === null
            || $fingerprintWindowBytes === null
            || $tusMetadataPath === null
            || $ffprobeBinary === null
            || $ffprobeTimeoutSeconds === null
            || $ffprobeMaxOutputBytes === null
            || $ffprobeMaxOutputBytes > 16_777_216
            || $ffprobeMaxStreams === null
            || $ffprobeMaxStreams > 1024
            || $processingJobTimeoutSeconds === null
            || $processingJobTimeoutSeconds <= $ffprobeTimeoutSeconds
            || $processingJobUniqueSeconds === null
            || $processingJobUniqueSeconds <= $processingJobTimeoutSeconds
            || $processingJobBackoffSeconds === null
            || $processingJobBackoffSeconds[0] < 1
            || $processingPollIntervalMilliseconds === null
            || $processingPollIntervalMilliseconds < 500
            || $processingPollIntervalMilliseconds > 10_000
            || $queueRetryAfterSeconds === null
            || $queueRetryAfterSeconds <= $processingJobTimeoutSeconds
        ) {
            throw new UploadAdmissionException(
                'upload_configuration_invalid',
                'Upload configuration is unavailable.',
                503,
            );
        }

        $this->tusPublicPath = $tusPublicPath;
        $this->tusInternalUrl = $tusInternalUrl;
        $this->hookSecret = $hookSecret;
        $this->chunkSizeBytes = $chunkSizeBytes;
        $this->retryDelaysMilliseconds = $retryDelaysMilliseconds;
        $this->internalConnectTimeoutSeconds = $internalConnectTimeoutSeconds;
        $this->internalTimeoutSeconds = $internalTimeoutSeconds;
        $this->tokenTtlSeconds = $tokenTtlSeconds;
        $this->tokenRefreshLeewaySeconds = $tokenRefreshLeewaySeconds;
        $this->inactivitySeconds = $inactivitySeconds;
        $this->fingerprintWindowBytes = $fingerprintWindowBytes;
        $this->tusMetadataPath = $tusMetadataPath;
        $this->ffprobeBinary = $ffprobeBinary;
        $this->ffprobeTimeoutSeconds = $ffprobeTimeoutSeconds;
        $this->ffprobeMaxOutputBytes = $ffprobeMaxOutputBytes;
        $this->ffprobeMaxStreams = $ffprobeMaxStreams;
        $this->processingJobTimeoutSeconds = $processingJobTimeoutSeconds;
        $this->processingJobUniqueSeconds = $processingJobUniqueSeconds;
        $this->processingJobBackoffSeconds = $processingJobBackoffSeconds;
        $this->processingPollIntervalMilliseconds = $processingPollIntervalMilliseconds;
        $this->queueRetryAfterSeconds = $queueRetryAfterSeconds;
    }

    public function hookSecretMatches(?string $candidate): bool
    {
        return is_string($candidate) && hash_equals($this->hookSecret, $candidate);
    }

    public function expiryMarker(): string
    {
        return $this->hookSecret;
    }

    public function expiryMarkerMatches(?string $candidate): bool
    {
        return $this->hookSecretMatches($candidate);
    }

    private function validPublicPath(string $path): bool
    {
        return preg_match('#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*/\z#', $path) === 1
            && ! str_contains($path, '//')
            && ! str_contains($path, '/./')
            && ! str_contains($path, '/../');
    }

    private function validInternalUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && isset($parts['path'])
            && $this->validPublicPath($parts['path']);
    }

    /** @return list<int>|null */
    private function retryDelays(mixed $value): ?array
    {
        $values = is_string($value) ? explode(',', $value) : $value;

        if (! is_array($values) || $values === [] || count($values) > 10) {
            return null;
        }

        $delays = [];

        foreach ($values as $delay) {
            if (is_string($delay) && preg_match('/\A(0|[1-9][0-9]*)\z/', trim($delay)) === 1) {
                $delay = (int) trim($delay);
            }

            if (! is_int($delay) || $delay < 0 || $delay > 300_000) {
                return null;
            }

            $delays[] = $delay;
        }

        return $delays;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', trim($value)) !== 1) {
            return null;
        }

        $integer = filter_var(trim($value), FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function absolutePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = rtrim(trim($value), '/');

        if ($path === ''
            || $path === '/'
            || ! str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return null;
        }

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    private function binary(mixed $value, bool $production): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $binary = trim($value);

        if (preg_match('/[\x00-\x20\x7F]/', $binary) === 1
            || str_contains($binary, '\\')
            || ($production && ! str_starts_with($binary, '/'))
        ) {
            return null;
        }

        return $binary;
    }
}
