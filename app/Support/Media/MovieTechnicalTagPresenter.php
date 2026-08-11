<?php

namespace App\Support\Media;

use App\Models\MediaFile;
use Illuminate\Support\Str;

final class MovieTechnicalTagPresenter
{
    /**
     * @return list<array{kind: 'quality'|'video'|'audio'|'duration', label: string}>
     */
    public function present(MediaFile $mediaFile): array
    {
        $videoStreams = $this->streams($mediaFile->video_metadata);
        $audioStreams = $this->streams($mediaFile->audio_metadata);
        $video = $this->primaryStream($videoStreams);
        $audio = $this->primaryStream($audioStreams);
        $tags = [];

        if ($video !== null) {
            $quality = $this->qualityLabel($video);

            if ($quality !== null) {
                $tags[] = ['kind' => 'quality', 'label' => $quality];
            }

            $videoCodec = $this->codecLabel($video['codec'] ?? null, [
                'h264' => 'H.264',
                'avc1' => 'H.264',
                'hevc' => 'HEVC',
                'h265' => 'HEVC',
                'hev1' => 'HEVC',
                'av1' => 'AV1',
                'av01' => 'AV1',
            ]);

            if ($videoCodec !== null) {
                $tags[] = ['kind' => 'video', 'label' => $videoCodec];
            }
        }

        if ($audio !== null) {
            $audioCodec = $this->codecLabel($audio['codec'] ?? null, [
                'aac' => 'AAC',
                'ac3' => 'Dolby Digital',
                'eac3' => 'Dolby Digital Plus',
                'truehd' => 'Dolby TrueHD',
                'dts' => 'DTS',
                'opus' => 'Opus',
                'flac' => 'FLAC',
                'mp3' => 'MP3',
            ]);

            if ($audioCodec !== null) {
                $channels = $this->channelLabel($audio);
                $additionalTracks = count($audioStreams) - 1;
                $label = $audioCodec;

                if ($channels !== null) {
                    $label .= ' '.$channels;
                }

                if ($additionalTracks > 0) {
                    $label .= ' +'.$additionalTracks;
                }

                $tags[] = ['kind' => 'audio', 'label' => Str::limit($label, 64, '')];
            }
        }

        $duration = $this->durationLabel($mediaFile->duration_milliseconds);

        if ($duration !== null) {
            $tags[] = ['kind' => 'duration', 'label' => $duration];
        }

        return array_slice($tags, 0, 4);
    }

    /**
     * @param  array<mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function streams(array $metadata): array
    {
        $streams = array_is_list($metadata) ? $metadata : [$metadata];

        $normalizedStreams = [];

        foreach ($streams as $stream) {
            if (! is_array($stream) || array_is_list($stream)) {
                continue;
            }

            $normalizedStream = [];

            foreach ($stream as $key => $value) {
                if (! is_string($key)) {
                    continue 2;
                }

                $normalizedStream[$key] = $value;
            }

            $normalizedStreams[] = $normalizedStream;
        }

        return $normalizedStreams;
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    private function primaryStream(array $streams): ?array
    {
        usort($streams, function (array $first, array $second): int {
            $defaultComparison = ((int) $this->isDefault($second)) <=> ((int) $this->isDefault($first));

            if ($defaultComparison !== 0) {
                return $defaultComparison;
            }

            return $this->streamIndex($first) <=> $this->streamIndex($second);
        });

        return $streams[0] ?? null;
    }

    /** @param array<string, mixed> $stream */
    private function isDefault(array $stream): bool
    {
        $disposition = $stream['disposition'] ?? null;

        return is_array($disposition) && ($disposition['default'] ?? false) === true;
    }

    /** @param array<string, mixed> $stream */
    private function streamIndex(array $stream): int
    {
        $index = $stream['index'] ?? null;

        return is_int($index) && $index >= 0 ? $index : PHP_INT_MAX;
    }

    /** @param array<string, mixed> $stream */
    private function qualityLabel(array $stream): ?string
    {
        $width = $stream['width'] ?? null;

        if (! is_int($width) || $width < 1) {
            return null;
        }

        $quality = match (true) {
            $width >= 7000 => '8K',
            $width >= 3000 => '4K',
            $width >= 2000 => '2K',
            $width >= 1600 => '1080p',
            $width >= 1100 => '720p',
            $width >= 700 => '480p',
            $width >= 500 => '360p',
            default => null,
        };

        if ($quality === null) {
            return null;
        }

        $dynamicRange = match ($stream['dynamic_range'] ?? null) {
            'dolby_vision' => 'Dolby Vision',
            'hdr10_plus' => 'HDR10+',
            'hdr10' => 'HDR10',
            'hlg' => 'HLG',
            default => null,
        };

        return $dynamicRange === null ? $quality : $quality.' · '.$dynamicRange;
    }

    /**
     * @param  array<string, string>  $knownCodecs
     */
    private function codecLabel(mixed $codec, array $knownCodecs): ?string
    {
        if (! is_string($codec) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,19}\z/i', $codec) !== 1) {
            return null;
        }

        $normalized = Str::lower($codec);

        return $knownCodecs[$normalized] ?? Str::upper(str_replace(['_', '-'], ' ', $normalized));
    }

    /** @param array<string, mixed> $stream */
    private function channelLabel(array $stream): ?string
    {
        $channels = $stream['channels'] ?? null;

        if (is_int($channels) && $channels > 0 && $channels <= 64) {
            return match ($channels) {
                1 => '1.0',
                2 => '2.0',
                6 => '5.1',
                8 => '7.1',
                default => $channels.'ch',
            };
        }

        $layout = $stream['channel_layout'] ?? null;

        if (! is_string($layout)) {
            return null;
        }

        $normalized = Str::of($layout)->lower()->before('(')->trim()->toString();

        return match (true) {
            $normalized === 'mono' => '1.0',
            $normalized === 'stereo' => '2.0',
            preg_match('/\A[0-9]{1,2}\.[0-9]\z/', $normalized) === 1 => $normalized,
            default => null,
        };
    }

    private function durationLabel(int $durationMilliseconds): ?string
    {
        if ($durationMilliseconds < 1) {
            return null;
        }

        $minutes = max(1, (int) round($durationMilliseconds / 60_000));
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return $minutes.'m';
        }

        return $remainingMinutes === 0
            ? $hours.'h'
            : $hours.'h '.$remainingMinutes.'m';
    }
}
