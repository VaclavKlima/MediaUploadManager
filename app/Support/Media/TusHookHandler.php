<?php

namespace App\Support\Media;

use App\Actions\TransitionUploadStatus;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Support\Media\Exceptions\UploadTransportException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TusHookHandler
{
    public function __construct(
        private TusUploadReconciler $reconciler,
        private TusUploadTokenIssuer $tokenIssuer,
        private TransitionUploadStatus $transitionUploadStatus,
        private UploadConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $type = $payload['Type'];

        try {
            return match ($type) {
                'pre-create' => $this->preCreate($payload),
                'post-create' => $this->postCreate($payload),
                'post-receive' => $this->postReceive($payload),
                'post-finish' => $this->postFinish($payload),
                'pre-terminate' => $this->preTerminate($payload),
                'post-terminate' => $this->postTerminate($payload),
                default => throw $this->unsafe('tus_hook_invalid'),
            };
        } catch (UploadTransportException $exception) {
            return match ($type) {
                'pre-create' => $this->blockingRejection('RejectUpload'),
                'pre-terminate' => $this->blockingRejection('RejectTermination'),
                'post-receive' => $this->blockingRejection('StopUpload'),
                default => throw $exception,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preCreate(array $payload): array
    {
        $event = $this->eventUpload($payload);
        $metadata = $event['MetaData'];
        $uuid = is_array($metadata) ? ($metadata['upload_uuid'] ?? null) : null;

        if (! is_string($uuid)
            || ! Str::isUuid($uuid, version: 7)
            || ! in_array($event['ID'] ?? null, [null, ''], true)
            || $event['Offset'] !== 0
            || ($event['Storage'] ?? null) !== null
        ) {
            throw $this->unsafe('tus_creation_invalid');
        }

        $upload = Upload::query()->where('uuid', $uuid)->first();

        if ($upload === null) {
            throw $this->unsafe('upload_not_found');
        }

        $this->validateEvent($upload, $event);
        $stagingPath = $this->reconciler->stagingPath($upload);

        DB::transaction(function () use ($upload): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUpload->status !== UploadStatus::Pending
                || $lockedUpload->tus_resource_id !== null
                || $lockedUpload->expires_at === null
                || $lockedUpload->expires_at->lessThanOrEqualTo(now())
            ) {
                throw $this->unsafe('upload_creation_forbidden');
            }

            if ($lockedUpload->tus_creation_claimed_at === null) {
                $lockedUpload->update(['tus_creation_claimed_at' => now()]);
            }
        }, attempts: 3);

        return [
            'ChangeFileInfo' => [
                'ID' => $upload->uuid,
                'MetaData' => ['upload_uuid' => $upload->uuid],
                'Storage' => ['Path' => $stagingPath],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postCreate(array $payload): array
    {
        [$upload, $event] = $this->identifiedEvent($payload);
        $this->validateEvent($upload, $event, requireStorage: true);

        DB::transaction(function () use ($upload): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();
            $this->confirmTusIdentity($lockedUpload);

            if ($lockedUpload->status === UploadStatus::Pending) {
                $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Uploading);
            }
        }, attempts: 3);

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postReceive(array $payload): array
    {
        [$upload, $event] = $this->identifiedEvent($payload);
        $this->validateEvent($upload, $event, requireStorage: true);
        $offset = $event['Offset'];

        DB::transaction(function () use ($upload, $offset): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();
            $this->confirmTusIdentity($lockedUpload);

            if (in_array($lockedUpload->status, [UploadStatus::Cancelled, UploadStatus::Expired, UploadStatus::Processing, UploadStatus::Completed, UploadStatus::Failed], true)) {
                return;
            }

            if ($offset <= $lockedUpload->confirmed_offset) {
                return;
            }

            if (in_array($lockedUpload->status, [UploadStatus::Pending, UploadStatus::Paused], true)) {
                $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Uploading);
            }

            $lockedUpload->update([
                'confirmed_offset' => max($lockedUpload->confirmed_offset, $offset),
                'last_activity_at' => now(),
                'expires_at' => now()->addSeconds($this->configuration->inactivitySeconds),
            ]);
        }, attempts: 3);

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postFinish(array $payload): array
    {
        [$upload, $event] = $this->identifiedEvent($payload);
        $this->validateEvent($upload, $event, requireStorage: true);

        if ($event['Offset'] !== $upload->declared_size) {
            throw $this->unsafe('upload_finish_incomplete');
        }

        if (in_array($upload->status, [UploadStatus::Cancelled, UploadStatus::Expired], true)) {
            return [];
        }

        DB::transaction(function () use ($upload): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();
            $this->confirmTusIdentity($lockedUpload);
        }, attempts: 3);

        $this->reconciler->reconcile($upload->refresh());

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preTerminate(array $payload): array
    {
        [$upload, $event] = $this->identifiedEvent($payload);
        $this->validateEvent($upload, $event, requireStorage: true);
        $isExpiry = $this->hasExpiryMarker($payload);

        DB::transaction(function () use ($upload, $event, $isExpiry): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if ($isExpiry) {
                if (! in_array($lockedUpload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true)
                    || $lockedUpload->expires_at === null
                    || $lockedUpload->expires_at->isFuture()
                    || $event['Offset'] !== $lockedUpload->confirmed_offset
                    || $event['Offset'] >= $lockedUpload->declared_size
                ) {
                    throw $this->unsafe('upload_expiry_termination_forbidden');
                }

                return;
            }

            if (! in_array($lockedUpload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused, UploadStatus::Cancelled], true)
                || $lockedUpload->confirmed_offset >= $lockedUpload->declared_size
                || $event['Offset'] >= $lockedUpload->declared_size
            ) {
                throw $this->unsafe('upload_termination_forbidden');
            }
        }, attempts: 3);

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postTerminate(array $payload): array
    {
        [$upload, $event] = $this->identifiedEvent($payload);
        $this->validateEvent($upload, $event, requireStorage: true);
        $isExpiry = $this->hasExpiryMarker($payload);

        DB::transaction(function () use ($upload, $event, $isExpiry): void {
            $lockedUpload = Upload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($lockedUpload->status, [UploadStatus::Processing, UploadStatus::Completed, UploadStatus::Failed, UploadStatus::Expired], true)) {
                return;
            }

            if ($isExpiry) {
                if (! in_array($lockedUpload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true)
                    || $event['Offset'] >= $lockedUpload->declared_size
                ) {
                    return;
                }

                $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Expired);
                $this->tokenIssuer->revoke($lockedUpload);

                return;
            }

            if ($lockedUpload->status !== UploadStatus::Cancelled) {
                $lockedUpload = $this->transitionUploadStatus->asSystem($lockedUpload, UploadStatus::Cancelled);
            }

            $this->tokenIssuer->revoke($lockedUpload);
        }, attempts: 3);

        return [];
    }

    /** @param array<string, mixed> $payload */
    private function hasExpiryMarker(array $payload): bool
    {
        $event = $payload['Event'] ?? null;

        if (! is_array($event)) {
            return false;
        }

        $request = $event['HTTPRequest'] ?? null;
        $headers = is_array($request) ? ($request['Header'] ?? null) : null;

        if (! is_array($headers)) {
            return false;
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || strcasecmp($name, 'X-Media-Upload-Expiry') !== 0) {
                continue;
            }

            if (is_string($value)) {
                return $this->configuration->expiryMarkerMatches($value);
            }

            if (is_array($value) && count($value) === 1 && is_string($value[0] ?? null)) {
                return $this->configuration->expiryMarkerMatches($value[0]);
            }

            return false;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{Upload, array<string, mixed>}
     */
    private function identifiedEvent(array $payload): array
    {
        $event = $this->eventUpload($payload);
        $id = $event['ID'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id, version: 7)) {
            throw $this->unsafe('tus_resource_invalid');
        }

        $upload = Upload::query()->where('uuid', $id)->first();

        if ($upload === null) {
            throw $this->unsafe('upload_not_found');
        }

        return [$upload, $event];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function eventUpload(array $payload): array
    {
        $eventContainer = $payload['Event'] ?? null;

        if (! is_array($eventContainer)) {
            throw $this->unsafe('tus_hook_invalid');
        }

        $event = $eventContainer['Upload'] ?? null;

        if (! is_array($event) || array_is_list($event)) {
            throw $this->unsafe('tus_hook_invalid');
        }

        $normalizedEvent = [];

        foreach ($event as $key => $value) {
            if (! is_string($key)) {
                throw $this->unsafe('tus_hook_invalid');
            }

            $normalizedEvent[$key] = $value;
        }

        return $normalizedEvent;
    }

    /** @param array<string, mixed> $event */
    private function validateEvent(Upload $upload, array $event, bool $requireStorage = false): void
    {
        $partialUploads = $event['PartialUploads'] ?? null;

        if ($event['SizeIsDeferred'] !== false
            || $event['Size'] !== $upload->declared_size
            || $event['Offset'] > $upload->declared_size
            || $event['IsPartial'] !== false
            || $event['IsFinal'] !== false
            || ! in_array($partialUploads, [null, []], true)
        ) {
            throw $this->unsafe('tus_state_invalid');
        }

        $metadata = $event['MetaData'] ?? null;

        if (! is_array($metadata)
            || array_keys($metadata) !== ['upload_uuid']
            || ($metadata['upload_uuid'] ?? null) !== $upload->uuid
        ) {
            throw $this->unsafe('tus_metadata_invalid');
        }

        if ($requireStorage) {
            $storage = $event['Storage'] ?? null;
            $path = is_array($storage) ? ($storage['Path'] ?? null) : null;

            if (! is_string($path) || ! hash_equals($this->reconciler->stagingPath($upload), $path)) {
                throw $this->unsafe('tus_storage_invalid');
            }
        }
    }

    private function confirmTusIdentity(Upload $upload): void
    {
        if ($upload->tus_resource_id !== null && $upload->tus_resource_id !== $upload->uuid) {
            throw $this->unsafe('tus_resource_mismatch');
        }

        $updates = [];

        if ($upload->tus_resource_id === null) {
            $updates['tus_resource_id'] = $upload->uuid;
        }

        if ($upload->tus_created_at === null) {
            $updates['tus_created_at'] = now();
        }

        if ($updates !== []) {
            $upload->update($updates);
        }
    }

    /** @return array<string, mixed> */
    private function blockingRejection(string $instruction): array
    {
        return [
            'HTTPResponse' => [
                'StatusCode' => 409,
                'Body' => json_encode(['message' => 'Upload transport request rejected.'], JSON_THROW_ON_ERROR),
                'Header' => ['Content-Type' => 'application/json'],
            ],
            $instruction => true,
        ];
    }

    private function unsafe(string $code): UploadTransportException
    {
        return new UploadTransportException(
            $code,
            'The upload transport state is invalid.',
        );
    }
}
