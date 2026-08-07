<?php

namespace App\Support\Media;

use App\Models\Upload;
use SensitiveParameter;

final readonly class UploadReservationResult
{
    public string $plaintextToken;

    public function __construct(
        public Upload $upload,
        #[SensitiveParameter] string $plaintextToken,
        public bool $idempotentReplay,
    ) {
        $this->plaintextToken = $plaintextToken;
    }
}
