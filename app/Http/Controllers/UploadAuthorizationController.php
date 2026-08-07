<?php

namespace App\Http\Controllers;

use App\Actions\IssueUploadAuthorization;
use App\Http\Requests\RefreshUploadAuthorizationRequest;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\UploadConfiguration;
use App\Support\Media\UploadSessionPresenter;
use Illuminate\Http\JsonResponse;

class UploadAuthorizationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        RefreshUploadAuthorizationRequest $request,
        Upload $upload,
        IssueUploadAuthorization $issueAuthorization,
        UploadSessionPresenter $presenter,
        UploadConfiguration $configuration,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        /** @var array{filename: string, declared_size: int, last_modified_milliseconds?: int|null, fingerprint_first_sha256: string, fingerprint_last_sha256: string} $fingerprint */
        $fingerprint = $request->validated();
        $result = $issueAuthorization->execute($upload, $user, $fingerprint);
        $authorizedUpload = $result['upload'];

        return response()->json([
            'data' => [
                ...$presenter->present($authorizedUpload),
                'endpoint' => $configuration->tusPublicPath,
                'resource_url' => $authorizedUpload->tus_resource_id === null
                    ? null
                    : $configuration->tusPublicPath.rawurlencode($authorizedUpload->tus_resource_id),
                'transport' => [
                    'chunk_size_bytes' => $configuration->chunkSizeBytes,
                    'retry_delays_milliseconds' => $configuration->retryDelaysMilliseconds,
                    'token_refresh_leeway_seconds' => $configuration->tokenRefreshLeewaySeconds,
                    'fingerprint_window_bytes' => $configuration->fingerprintWindowBytes,
                ],
                'authorization' => [
                    'token' => $result['token'],
                    'abilities' => $authorizedUpload->token_abilities,
                    'expires_at' => $authorizedUpload->token_expires_at?->toISOString(),
                ],
            ],
        ]);
    }
}
