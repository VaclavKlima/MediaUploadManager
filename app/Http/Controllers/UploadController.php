<?php

namespace App\Http\Controllers;

use App\Actions\CancelUpload;
use App\Actions\DiscardFailedUpload;
use App\Actions\RetryFailedUpload;
use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\TusUploadReconciler;
use App\Support\Media\UploadConfiguration;
use App\Support\Media\UploadSessionPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function index(
        Request $request,
        UploadSessionPresenter $presenter,
        UploadConfiguration $configuration,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $uploads = Upload::query()
            ->whereBelongsTo($user)
            ->whereIn('status', [
                UploadStatus::Pending,
                UploadStatus::Uploading,
                UploadStatus::Paused,
                UploadStatus::Processing,
                UploadStatus::Failed,
            ])
            ->latest()
            ->get()
            ->map(fn (Upload $upload): array => $presenter->present($upload))
            ->values();

        return response()->json([
            'data' => $uploads,
            'meta' => [
                'fingerprint_window_bytes' => $configuration->fingerprintWindowBytes,
            ],
        ]);
    }

    public function show(
        Request $request,
        Upload $upload,
        TusUploadReconciler $reconciler,
        UploadSessionPresenter $presenter,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorizeUpload($upload, $user);

        $presentedUpload = in_array($upload->status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Paused], true)
            ? $reconciler->reconcile($upload)
            : $upload->refresh();

        return response()->json(['data' => $presenter->present($presentedUpload)]);
    }

    public function destroy(
        Request $request,
        Upload $upload,
        CancelUpload $cancelUpload,
        DiscardFailedUpload $discardFailedUpload,
        UploadSessionPresenter $presenter,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $cancelledUpload = $upload->status === UploadStatus::Failed
            ? $discardFailedUpload->execute($upload, $user)
            : $cancelUpload->execute($upload, $user);

        return response()->json([
            'data' => $presenter->present($cancelledUpload),
        ]);
    }

    public function retry(
        Request $request,
        Upload $upload,
        RetryFailedUpload $retryFailedUpload,
        UploadSessionPresenter $presenter,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);

        return response()->json([
            'data' => $presenter->present($retryFailedUpload->execute($upload, $user)),
        ]);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function authorizeUpload(Upload $upload, User $user): void
    {
        if ($upload->user_id !== $user->getKey() && ! $user->isAdministrator()) {
            throw new AuthorizationException('You do not own this upload.');
        }
    }
}
