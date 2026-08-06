<?php

use App\ValueObjects\ByteCount;
use App\ValueObjects\LocalFileFingerprint;
use App\ValueObjects\RelativeMediaPath;
use App\ValueObjects\TokenHash;

it('calculates nonnegative remaining byte counts', function () {
    expect((new ByteCount(100))->remainingAfter(40)->value)->toBe(60)
        ->and((new ByteCount(100))->remainingAfter(140)->value)->toBe(0);
});

it('rejects negative byte counts', function () {
    new ByteCount(-1);
})->throws(InvalidArgumentException::class);

it('accepts safe relative media paths', function () {
    $path = new RelativeMediaPath('Movies/Amélie (2001) [tmdbid-194]/Amélie.mkv');

    expect($path->value)->toBe('Movies/Amélie (2001) [tmdbid-194]/Amélie.mkv');
});

it('rejects unsafe relative media paths', function (string $path) {
    new RelativeMediaPath($path);
})->with([
    'empty' => '',
    'absolute' => '/Movies/movie.mkv',
    'windows absolute' => 'C:\\Movies\\movie.mkv',
    'parent traversal' => 'Movies/../movie.mkv',
    'current segment' => 'Movies/./movie.mkv',
    'empty segment' => 'Movies//movie.mkv',
    'control byte' => "Movies/movie\nmkv",
])->throws(InvalidArgumentException::class);

it('validates and normalizes local file fingerprints', function () {
    $fingerprint = new LocalFileFingerprint(
        new ByteCount(10_000_000_000),
        1_750_000_000_000,
        str_repeat('A', 64),
        str_repeat('b', 64),
    );

    expect($fingerprint->firstSha256)->toBe(str_repeat('a', 64))
        ->and($fingerprint->lastSha256)->toBe(str_repeat('b', 64));
});

it('rejects malformed local file fingerprints', function (?int $modified, string $digest) {
    new LocalFileFingerprint(new ByteCount(1), $modified, $digest, str_repeat('a', 64));
})->with([
    'negative modified time' => [-1, str_repeat('a', 64)],
    'short digest' => [1, str_repeat('a', 63)],
    'non hexadecimal digest' => [1, str_repeat('z', 64)],
])->throws(InvalidArgumentException::class);

it('creates nonreversible sha256 token hashes and compares them safely', function () {
    $hash = TokenHash::fromPlaintext('one-time-upload-token');

    expect($hash->value)->toHaveLength(64)
        ->and($hash->value)->not->toContain('one-time-upload-token')
        ->and($hash->matches('one-time-upload-token'))->toBeTrue()
        ->and($hash->matches('wrong-token'))->toBeFalse()
        ->and(TokenHash::fromHash(strtoupper($hash->value))->value)->toBe($hash->value);
});

it('rejects malformed token hashes', function (string $hash) {
    TokenHash::fromHash($hash);
})->with(['', 'plaintext-token', str_repeat('g', 64)])->throws(InvalidArgumentException::class);
