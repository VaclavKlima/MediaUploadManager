<?php

namespace App\Support\Media;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Support\Media\Exceptions\UploadTransportException;
use App\ValueObjects\TokenHash;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class TusRequestAuthorizer
{
    public function __construct(
        private UploadConfiguration $configuration,
        private TusUploadReconciler $reconciler,
    ) {}

    public function authorize(Request $request): Upload
    {
        $method = strtoupper((string) $request->header('X-Original-Method'));
        $uri = (string) $request->header('X-Original-Uri');
        $ability = TusMethodAbility::for($method);
        $token = $request->bearerToken();

        if ($ability === null || $token === null || $token === '') {
            throw $this->denied('upload_authorization_required', 401);
        }

        $upload = Upload::query()
            ->where('token_hash', TokenHash::fromPlaintext($token)->value)
            ->first();

        if ($upload === null
            || $upload->token_expires_at === null
            || $upload->token_expires_at->lessThanOrEqualTo(now())
            || ! in_array($ability, $upload->token_abilities ?? [], true)
            || $upload->expires_at === null
            || $upload->expires_at->lessThanOrEqualTo(now())
        ) {
            throw $this->denied('upload_authorization_invalid', 401);
        }

        if ((string) $request->header('Tus-Resumable') !== '1.0.0') {
            throw $this->denied('tus_protocol_invalid');
        }

        $requestUuid = $method === 'POST'
            ? $this->creationUuid($request, $uri)
            : $this->resourceUuid($uri);

        if (! hash_equals($upload->uuid, $requestUuid)) {
            throw $this->denied('upload_identity_mismatch');
        }

        $this->validateMethodState($request, $upload, $method);
        $this->reconciler->stagingPath($upload);

        return $upload;
    }

    private function creationUuid(Request $request, string $uri): string
    {
        if ($uri !== $this->configuration->tusPublicPath
            || $request->header('Upload-Defer-Length') !== null
            || $request->header('Upload-Concat') !== null
        ) {
            throw $this->denied('tus_creation_invalid');
        }

        $length = $this->nonnegativeInteger($request->header('Upload-Length'));
        $metadata = $this->metadata((string) $request->header('Upload-Metadata'));

        if ($length === null || array_keys($metadata) !== ['upload_uuid']) {
            throw $this->denied('tus_creation_invalid');
        }

        $uuid = $metadata['upload_uuid'];

        if (! Str::isUuid($uuid, version: 7)) {
            throw $this->denied('tus_creation_invalid');
        }

        return $uuid;
    }

    private function resourceUuid(string $uri): string
    {
        $prefix = $this->configuration->tusPublicPath;

        if (! str_starts_with($uri, $prefix) || str_contains($uri, '?')) {
            throw $this->denied('tus_resource_invalid');
        }

        $resourceId = rawurldecode(Str::after($uri, $prefix));

        if ($resourceId === '' || str_contains($resourceId, '/') || ! Str::isUuid($resourceId, version: 7)) {
            throw $this->denied('tus_resource_invalid');
        }

        return $resourceId;
    }

    private function validateMethodState(Request $request, Upload $upload, string $method): void
    {
        $allowedStatuses = match ($method) {
            'POST' => [UploadStatus::Pending],
            'HEAD' => [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused],
            'PATCH', 'DELETE' => [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused],
            default => [],
        };

        if (! in_array($upload->status, $allowedStatuses, true)) {
            throw $this->denied('upload_state_forbidden');
        }

        if ($method === 'POST') {
            $length = $this->nonnegativeInteger($request->header('Upload-Length'));

            if ($upload->tus_resource_id !== null || $length !== $upload->declared_size) {
                throw $this->denied('tus_creation_invalid');
            }
        }

        if ($method === 'PATCH') {
            $offset = $this->nonnegativeInteger($request->header('Upload-Offset'));

            if ($offset === null
                || $offset < $upload->confirmed_offset
                || $offset > $upload->declared_size
                || $request->header('Upload-Defer-Length') !== null
            ) {
                throw $this->denied('tus_offset_invalid');
            }
        }
    }

    /** @return array<string, string> */
    private function metadata(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $metadata = [];

        foreach (explode(',', $header) as $item) {
            $parts = explode(' ', trim($item), 2);

            if (count($parts) !== 2 || preg_match('/\A[A-Za-z0-9_-]+\z/', $parts[0]) !== 1) {
                throw $this->denied('tus_metadata_invalid');
            }

            $decoded = base64_decode($parts[1], true);

            if ($decoded === false || isset($metadata[$parts[0]])) {
                throw $this->denied('tus_metadata_invalid');
            }

            $metadata[$parts[0]] = $decoded;
        }

        return $metadata;
    }

    private function nonnegativeInteger(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer >= 0 ? $integer : null;
    }

    private function denied(string $code, int $status = 403): UploadTransportException
    {
        return new UploadTransportException($code, 'Upload authorization was denied.', $status);
    }
}
