<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class LocalFileFingerprint
{
    public string $firstSha256;

    public string $lastSha256;

    public function __construct(
        public ByteCount $size,
        public ?int $lastModifiedMilliseconds,
        string $firstSha256,
        string $lastSha256,
    ) {
        if ($lastModifiedMilliseconds !== null && $lastModifiedMilliseconds < 0) {
            throw new InvalidArgumentException('Last-modified milliseconds cannot be negative.');
        }

        $this->firstSha256 = self::validateDigest($firstSha256);
        $this->lastSha256 = self::validateDigest($lastSha256);
    }

    private static function validateDigest(string $digest): string
    {
        $normalizedDigest = strtolower($digest);

        if (preg_match('/\A[a-f0-9]{64}\z/', $normalizedDigest) !== 1) {
            throw new InvalidArgumentException('A local-file fingerprint digest must be a SHA-256 hash.');
        }

        return $normalizedDigest;
    }
}
