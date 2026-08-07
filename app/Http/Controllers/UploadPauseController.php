<?php

namespace App\Http\Controllers;

use App\Actions\PauseUpload;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\UploadSessionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadPauseController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        Upload $upload,
        PauseUpload $pauseUpload,
        UploadSessionPresenter $presenter,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return response()->json([
            'data' => $presenter->present($pauseUpload->execute($upload, $user)),
        ]);
    }
}
