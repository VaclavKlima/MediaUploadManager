<?php

use App\Support\Media\Exceptions\UploadProcessingException;
use App\Support\Media\FfprobeMediaValidator;
use App\Support\Media\UploadConfiguration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $whichFfmpeg = Process::run(['/usr/bin/which', 'ffmpeg']);
    $whichFfprobe = Process::run(['/usr/bin/which', 'ffprobe']);

    if ($whichFfmpeg->failed() || $whichFfprobe->failed()) {
        $this->markTestSkipped('The real ffmpeg/ffprobe integration requires `brew install ffmpeg`.');
    }

    $this->ffmpegBinary = trim($whichFfmpeg->output());
    $this->ffprobeBinary = trim($whichFfprobe->output());
    $this->probeRoot = storage_path('framework/testing/ffprobe-'.bin2hex(random_bytes(6)));
    (new Filesystem)->makeDirectory($this->probeRoot, 0750, true);
    config()->set('upload.ffprobe_binary', $this->ffprobeBinary);
    app()->forgetInstance(UploadConfiguration::class);
});

afterEach(function () {
    if (isset($this->probeRoot)) {
        (new Filesystem)->deleteDirectory($this->probeRoot);
    }
});

it('validates a tiny real video and rejects audio-only, corrupt, and truncated fixtures', function () {
    $validPath = $this->probeRoot.'/valid.mp4';
    $audioPath = $this->probeRoot.'/audio.m4a';
    $corruptPath = $this->probeRoot.'/corrupt.mp4';
    $truncatedPath = $this->probeRoot.'/truncated.mp4';

    Process::timeout(30)->run([
        $this->ffmpegBinary,
        '-v',
        'error',
        '-f',
        'lavfi',
        '-i',
        'color=c=black:s=16x16:d=1',
        '-c:v',
        'mpeg4',
        '-y',
        $validPath,
    ])->throw();
    Process::timeout(30)->run([
        $this->ffmpegBinary,
        '-v',
        'error',
        '-f',
        'lavfi',
        '-i',
        'sine=frequency=1000:duration=1',
        '-c:a',
        'aac',
        '-y',
        $audioPath,
    ])->throw();

    file_put_contents($corruptPath, 'not media');
    $validBytes = file_get_contents($validPath);
    file_put_contents($truncatedPath, substr($validBytes, 0, intdiv(strlen($validBytes), 2)));
    $validator = app(FfprobeMediaValidator::class);

    $result = $validator->probe($validPath);

    expect($result['container'])->toBe('quicktime')
        ->and($result['duration_milliseconds'])->toBeGreaterThan(0)
        ->and($result['video'][0]['width'])->toBe(16)
        ->and($result['video'][0]['height'])->toBe(16);

    foreach ([$audioPath, $corruptPath, $truncatedPath] as $invalidPath) {
        expect(fn () => $validator->probe($invalidPath))->toThrow(UploadProcessingException::class);
    }
});
