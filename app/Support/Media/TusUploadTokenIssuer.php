<?php

namespace App\Support\Media;

use App\Models\Upload;
use App\ValueObjects\TokenHash;

final readonly class TusUploadTokenIssuer
{
    public function __construct(private UploadConfiguration $configuration) {}

    public function rotate(Upload $upload): string
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $now = now();

        $upload->update([
            'token_hash' => TokenHash::fromPlaintext($plaintextToken)->value,
            'token_abilities' => TusMethodAbility::all(),
            'token_expires_at' => $now->addSeconds($this->configuration->tokenTtlSeconds),
            'last_activity_at' => $now,
        ]);

        return $plaintextToken;
    }

    public function revoke(Upload $upload): void
    {
        $upload->update([
            'token_hash' => null,
            'token_abilities' => null,
            'token_expires_at' => null,
        ]);
    }
}
