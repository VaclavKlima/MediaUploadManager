<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrReplayUploadReservation;
use App\Http\Requests\StoreMovieUploadRequest;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Media\UploadConfiguration;
use Illuminate\Http\JsonResponse;

class MovieUploadController extends Controller
{
    public function store(
        StoreMovieUploadRequest $request,
        MediaItem $mediaItem,
        CreateOrReplayUploadReservation $reservationAction,
        ConfiguredDiskRegistry $diskRegistry,
        UploadConfiguration $uploadConfiguration,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        /** @var array{idempotency_key: string, filename: string, declared_size: int, last_modified_milliseconds?: int|null, fingerprint_first_sha256: string, fingerprint_last_sha256: string, disk_id: string, replaces_media_file_id?: int|null, replacement_confirmed?: mixed} $input */
        $input = $request->validated();
        $input['replacement_confirmed'] = $request->boolean('replacement_confirmed');

        try {
            $result = $reservationAction->execute($user, $mediaItem, $input);
            $disk = $diskRegistry->find($result->upload->disk_id);
            $replacedMediaFile = $result->upload->replacesMediaFile()->first();
        } catch (UploadAdmissionException $exception) {
            return response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->status);
        } catch (MediaConfigurationException) {
            return response()->json([
                'error' => 'media_configuration_unavailable',
                'message' => 'Media disk configuration is unavailable.',
            ], 503);
        }

        return response()->json([
            'data' => [
                'uuid' => $result->upload->uuid,
                'status' => $result->upload->status->value,
                'original_filename' => $result->upload->original_filename,
                'last_modified_milliseconds' => $result->upload->last_modified_milliseconds,
                'disk' => [
                    'id' => $result->upload->disk_id,
                    'label' => $disk?->label,
                ],
                'target_relative_path' => $result->upload->target_relative_path,
                'staging_relative_path' => $result->upload->staging_relative_path,
                'declared_bytes' => $result->upload->declared_size,
                'confirmed_bytes' => $result->upload->confirmed_offset,
                'replacement' => $replacedMediaFile === null ? null : [
                    'media_file_id' => $replacedMediaFile->getKey(),
                    'disk' => [
                        'id' => $replacedMediaFile->disk_id,
                        'label' => $diskRegistry->find($replacedMediaFile->disk_id)?->label,
                    ],
                    'relative_path' => $replacedMediaFile->relative_path,
                    'size_bytes' => $replacedMediaFile->size_bytes,
                    'confirmed_at' => $result->upload->replacement_confirmed_at?->toISOString(),
                    'method' => $replacedMediaFile->disk_id === $result->upload->disk_id
                        && $replacedMediaFile->relative_path === $result->upload->target_relative_path
                            ? 'atomic_same_path_swap'
                            : 'finalize_then_delete',
                ],
                'expires_at' => $result->upload->expires_at?->toISOString(),
                'tus_endpoint' => $uploadConfiguration->tusPublicPath,
                'tus_resource_url' => null,
                'transport' => [
                    'chunk_size_bytes' => $uploadConfiguration->chunkSizeBytes,
                    'retry_delays_milliseconds' => $uploadConfiguration->retryDelaysMilliseconds,
                    'token_refresh_leeway_seconds' => $uploadConfiguration->tokenRefreshLeewaySeconds,
                    'fingerprint_window_bytes' => $uploadConfiguration->fingerprintWindowBytes,
                ],
                'authorization' => [
                    'token' => $result->plaintextToken,
                    'abilities' => $result->upload->token_abilities,
                    'expires_at' => $result->upload->token_expires_at?->toISOString(),
                ],
                'idempotent_replay' => $result->idempotentReplay,
            ],
        ], $result->idempotentReplay ? 200 : 201);
    }
}
