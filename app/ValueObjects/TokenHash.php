<?php

namespace App\ValueObjects;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class TokenHash
{
    private function __construct(public string $value) {}

    public static function fromPlaintext(#[SensitiveParameter] string $token): self
    {
        if ($token === '') {
            throw new InvalidArgumentException('A plaintext token cannot be empty.');
        }

        return new self(hash('sha256', $token));
    }

    public static function fromHash(string $hash): self
    {
        $normalizedHash = strtolower($hash);

        if (preg_match('/\A[a-f0-9]{64}\z/', $normalizedHash) !== 1) {
            throw new InvalidArgumentException('A token hash must be a SHA-256 hash.');
        }

        return new self($normalizedHash);
    }

    public function matches(#[SensitiveParameter] string $token): bool
    {
        return hash_equals($this->value, hash('sha256', $token));
    }
}
