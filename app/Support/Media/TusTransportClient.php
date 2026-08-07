<?php

namespace App\Support\Media;

use App\Models\Upload;
use App\Support\Media\Exceptions\UploadTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class TusTransportClient
{
    public function __construct(private UploadConfiguration $configuration) {}

    /** @return array{offset: int, length: int}|null */
    public function head(Upload $upload): ?array
    {
        try {
            $response = $this->request()->head($this->internalResourceUrl($upload));
        } catch (ConnectionException $exception) {
            throw $this->unavailable($exception);
        }

        if ($response->notFound()) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->unavailable();
        }

        $offset = $this->nonnegativeHeader($response->header('Upload-Offset'));
        $length = $this->nonnegativeHeader($response->header('Upload-Length'));

        if ($offset === null || $length === null || $offset > $length) {
            throw new UploadTransportException(
                'upload_state_inconsistent',
                'The upload state could not be verified safely.',
            );
        }

        return ['offset' => $offset, 'length' => $length];
    }

    public function terminate(Upload $upload): void
    {
        try {
            $response = $this->request()->delete($this->internalResourceUrl($upload));
        } catch (ConnectionException $exception) {
            throw $this->unavailable($exception);
        }

        if (! $response->successful() && ! $response->notFound()) {
            throw $this->unavailable();
        }
    }

    private function request(): PendingRequest
    {
        return Http::connectTimeout($this->configuration->internalConnectTimeoutSeconds)
            ->timeout($this->configuration->internalTimeoutSeconds)
            ->withHeaders(['Tus-Resumable' => '1.0.0']);
    }

    private function internalResourceUrl(Upload $upload): string
    {
        $resourceId = $upload->tus_resource_id ?? $upload->uuid;

        return $this->configuration->tusInternalUrl.rawurlencode($resourceId);
    }

    private function nonnegativeHeader(?string $value): ?int
    {
        if (! is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer >= 0 ? $integer : null;
    }

    private function unavailable(?Throwable $previous = null): UploadTransportException
    {
        return new UploadTransportException(
            'upload_transport_unavailable',
            'The upload transport is temporarily unavailable.',
            503,
            $previous,
        );
    }
}
