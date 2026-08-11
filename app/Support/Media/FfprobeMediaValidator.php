<?php

namespace App\Support\Media;

use App\Support\Media\Exceptions\UploadProcessingException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final readonly class FfprobeMediaValidator
{
    public function __construct(private UploadConfiguration $configuration) {}

    /**
     * @return array{
     *     container: string,
     *     duration_milliseconds: int,
     *     video: list<array<string, mixed>>,
     *     audio: list<array<string, mixed>>,
     *     snapshot: array<string, mixed>
     * }
     */
    public function probe(string $path): array
    {
        try {
            $capturedOutput = '';
            $result = Process::timeout($this->configuration->ffprobeTimeoutSeconds)
                ->run($this->command($path), function (string $type, string $chunk) use (&$capturedOutput): void {
                    if ($type !== 'out') {
                        return;
                    }

                    $capturedOutput .= $chunk;

                    if (strlen($capturedOutput) > $this->configuration->ffprobeMaxOutputBytes) {
                        throw UploadProcessingException::permanent(
                            'media_probe_output_too_large',
                            'The media contains too much technical metadata to validate safely.',
                        );
                    }
                });
        } catch (UploadProcessingException $exception) {
            throw $exception;
        } catch (ProcessTimedOutException $exception) {
            throw UploadProcessingException::transient(
                'media_probe_timeout',
                'Media validation timed out and may be retried.',
                $exception,
            );
        } catch (Throwable $exception) {
            throw UploadProcessingException::transient(
                'media_probe_unavailable',
                'Media validation is temporarily unavailable.',
                $exception,
            );
        }

        $output = $capturedOutput !== '' ? $capturedOutput : $result->output();

        if (strlen($output) > $this->configuration->ffprobeMaxOutputBytes) {
            throw UploadProcessingException::permanent(
                'media_probe_output_too_large',
                'The media contains too much technical metadata to validate safely.',
            );
        }

        if ($result->failed()) {
            throw UploadProcessingException::permanent(
                'media_probe_failed',
                'The staged file is not a complete readable media file.',
            );
        }

        return $this->parse($output);
    }

    /** @return list<string> */
    public function command(string $path): array
    {
        return [
            $this->configuration->ffprobeBinary,
            '-v',
            'error',
            '-show_entries',
            'format=format_name,duration:stream=index,codec_type,codec_name,width,height,color_transfer,channels,channel_layout,sample_rate:stream_tags=language:stream_disposition=default,forced,hearing_impaired,visual_impaired,comment,original,dub:stream_side_data=side_data_type',
            '-of',
            'json',
            $path,
        ];
    }

    /**
     * @return array{
     *     container: string,
     *     duration_milliseconds: int,
     *     video: list<array<string, mixed>>,
     *     audio: list<array<string, mixed>>,
     *     snapshot: array<string, mixed>
     * }
     */
    public function parse(string $output): array
    {
        if ($output === '' || strlen($output) > $this->configuration->ffprobeMaxOutputBytes) {
            throw UploadProcessingException::permanent(
                'media_probe_invalid',
                'The media technical metadata is invalid.',
            );
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw UploadProcessingException::permanent(
                'media_probe_invalid_json',
                'The media technical metadata is invalid.',
                $exception,
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw UploadProcessingException::permanent('media_probe_invalid', 'The media technical metadata is invalid.');
        }

        $format = $decoded['format'] ?? null;
        $streams = $decoded['streams'] ?? null;

        if (! is_array($format) || array_is_list($format) || ! is_array($streams) || ! array_is_list($streams)) {
            throw UploadProcessingException::permanent('media_probe_invalid', 'The media technical metadata is invalid.');
        }

        if ($streams === [] || count($streams) > $this->configuration->ffprobeMaxStreams) {
            throw UploadProcessingException::permanent(
                'media_stream_count_invalid',
                'The media stream count is outside the safe validation limit.',
            );
        }

        $container = $this->normalizeContainer($format['format_name'] ?? null);
        $durationMilliseconds = $this->durationMilliseconds($format['duration'] ?? null);
        $video = [];
        $audio = [];
        $snapshotStreams = [];

        foreach ($streams as $stream) {
            if (! is_array($stream) || array_is_list($stream)) {
                throw UploadProcessingException::permanent('media_probe_invalid', 'The media technical metadata is invalid.');
            }

            $normalizedStream = [];

            foreach ($stream as $key => $value) {
                if (! is_string($key)) {
                    throw UploadProcessingException::permanent('media_probe_invalid', 'The media technical metadata is invalid.');
                }

                $normalizedStream[$key] = $value;
            }

            $stream = $normalizedStream;

            $type = $stream['codec_type'] ?? null;
            $codec = $this->safeToken($stream['codec_name'] ?? null);
            $index = $this->nonnegativeInteger($stream['index'] ?? null);

            if ($index === null || ! in_array($type, ['video', 'audio'], true)) {
                continue;
            }

            $summary = [
                'index' => $index,
                'codec' => $codec,
                'language' => $this->language($stream),
                'disposition' => $this->disposition($stream),
            ];

            if ($type === 'video') {
                $width = $this->positiveInteger($stream['width'] ?? null);
                $height = $this->positiveInteger($stream['height'] ?? null);

                if ($codec === null || $width === null || $height === null) {
                    throw UploadProcessingException::permanent(
                        'media_video_stream_invalid',
                        'Every video stream must have a codec and positive dimensions.',
                    );
                }

                $summary['width'] = $width;
                $summary['height'] = $height;
                $summary['dynamic_range'] = $this->dynamicRange($stream);
                $video[] = $summary;
            } else {
                if ($codec === null) {
                    throw UploadProcessingException::permanent(
                        'media_audio_stream_invalid',
                        'Every audio stream must have a recognized codec.',
                    );
                }

                $summary['channels'] = $this->positiveInteger($stream['channels'] ?? null);
                $summary['channel_layout'] = $this->safeText($stream['channel_layout'] ?? null, 64);
                $summary['sample_rate'] = $this->positiveInteger($stream['sample_rate'] ?? null);
                $audio[] = $summary;
            }

            $snapshotStreams[] = ['type' => $type, ...$summary];
        }

        if ($video === []) {
            throw UploadProcessingException::permanent(
                'media_video_missing',
                'The staged media does not contain a valid video stream.',
            );
        }

        return [
            'container' => $container,
            'duration_milliseconds' => $durationMilliseconds,
            'video' => $video,
            'audio' => $audio,
            'snapshot' => [
                'format' => [
                    'container' => $container,
                    'duration_milliseconds' => $durationMilliseconds,
                ],
                'streams' => $snapshotStreams,
            ],
        ];
    }

    private function normalizeContainer(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw UploadProcessingException::permanent('media_container_invalid', 'The media container is not recognized.');
        }

        $recognized = [
            'matroska' => 'matroska',
            'webm' => 'webm',
            'mov' => 'quicktime',
            'mp4' => 'mp4',
            'm4a' => 'mp4',
            '3gp' => 'mp4',
            '3g2' => 'mp4',
            'mj2' => 'mp4',
            'avi' => 'avi',
            'mpegts' => 'mpegts',
        ];

        foreach (explode(',', Str::lower($value)) as $candidate) {
            if (isset($recognized[$candidate])) {
                return $recognized[$candidate];
            }
        }

        throw UploadProcessingException::permanent('media_container_invalid', 'The media container is not recognized.');
    }

    /** @param array<string, mixed> $stream */
    private function dynamicRange(array $stream): string
    {
        $sideDataTypes = [];
        $sideDataList = $stream['side_data_list'] ?? null;

        if (is_array($sideDataList) && array_is_list($sideDataList)) {
            foreach (array_slice($sideDataList, 0, 64) as $sideData) {
                if (! is_array($sideData) || array_is_list($sideData)) {
                    continue;
                }

                $sideDataType = $this->safeText($sideData['side_data_type'] ?? null, 128);

                if ($sideDataType !== null) {
                    $sideDataTypes[] = Str::lower($sideDataType);
                }
            }
        }

        if (collect($sideDataTypes)->contains(
            fn (string $type): bool => Str::contains($type, ['dovi', 'dolby vision']),
        )) {
            return 'dolby_vision';
        }

        if (collect($sideDataTypes)->contains(
            fn (string $type): bool => Str::contains($type, ['smpte2094-40', 'hdr10+']),
        )) {
            return 'hdr10_plus';
        }

        $rawColorTransfer = $stream['color_transfer'] ?? null;
        $colorTransfer = is_string($rawColorTransfer)
            && preg_match('/\A[a-z0-9_-]{1,64}\z/i', $rawColorTransfer) === 1
                ? Str::lower($rawColorTransfer)
                : null;

        if ($colorTransfer === 'smpte2084'
            || collect($sideDataTypes)->contains(
                fn (string $type): bool => Str::contains($type, [
                    'mastering display metadata',
                    'content light level metadata',
                ]),
            )
        ) {
            return 'hdr10';
        }

        if ($colorTransfer === 'arib-std-b67') {
            return 'hlg';
        }

        if (in_array($colorTransfer, [
            'bt709',
            'gamma22',
            'gamma28',
            'smpte170m',
            'smpte240m',
            'linear',
            'log',
            'log_sqrt',
            'iec61966-2-1',
            'bt1361e',
            'bt2020-10',
            'bt2020-12',
            'iec61966-2-4',
        ], true)) {
            return 'sdr';
        }

        return 'unknown';
    }

    private function durationMilliseconds(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw UploadProcessingException::permanent('media_duration_invalid', 'The media duration must be finite and positive.');
        }

        $seconds = filter_var($value, FILTER_VALIDATE_FLOAT);

        if (! is_float($seconds) || ! is_finite($seconds) || $seconds <= 0 || $seconds > PHP_INT_MAX / 1000) {
            throw UploadProcessingException::permanent('media_duration_invalid', 'The media duration must be finite and positive.');
        }

        $milliseconds = (int) round($seconds * 1000);

        if ($milliseconds < 1) {
            throw UploadProcessingException::permanent('media_duration_invalid', 'The media duration must be finite and positive.');
        }

        return $milliseconds;
    }

    /**
     * @param  array<string, mixed>  $stream
     * @return array<string, bool>
     */
    private function disposition(array $stream): array
    {
        $raw = $stream['disposition'] ?? [];
        $disposition = [];

        foreach (['default', 'forced', 'hearing_impaired', 'visual_impaired', 'comment', 'original', 'dub'] as $key) {
            $disposition[$key] = is_array($raw) && ($raw[$key] ?? 0) === 1;
        }

        return $disposition;
    }

    /** @param array<string, mixed> $stream */
    private function language(array $stream): ?string
    {
        $tags = $stream['tags'] ?? null;
        $language = is_array($tags) ? ($tags['language'] ?? null) : null;

        if (! is_string($language)) {
            return null;
        }

        $normalized = Str::lower(trim($language));

        return preg_match('/\A[a-z0-9-]{2,35}\z/', $normalized) === 1 ? $normalized : null;
    }

    private function safeToken(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-z0-9_]{1,64}\z/i', $value) === 1
            ? Str::lower($value)
            : null;
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $safe = Str::of($value)->replaceMatches('/[\x00-\x1F\x7F]/u', ' ')->squish()->limit($limit, '')->toString();

        return $safe === '' ? null : $safe;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = $this->nonnegativeInteger($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function nonnegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer >= 0 ? $integer : null;
    }
}
