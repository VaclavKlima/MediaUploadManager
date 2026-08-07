<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoviePathPreviewRequest;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\UploadCapacityPlanner;
use App\Support\Media\UploadConfiguration;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class MoviePathPreviewController extends Controller
{
    public function __construct(
        private readonly JellyfinMoviePathBuilder $pathBuilder,
        private readonly UploadCapacityPlanner $capacityPlanner,
        private readonly UploadConfiguration $uploadConfiguration,
    ) {}

    public function __invoke(MoviePathPreviewRequest $request, MediaItem $mediaItem): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $path = $this->pathBuilder->build(
                $mediaItem,
                $request->string('filename')->toString(),
            );
            $capacity = $this->capacityPlanner->plan(
                $mediaItem,
                $path,
                $request->integer('declared_size'),
                $user,
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'error' => 'path_preview_invalid',
                'message' => 'A destination preview cannot be built from this movie and filename.',
            ], 422);
        } catch (MediaConfigurationException) {
            return response()->json([
                'error' => 'media_configuration_unavailable',
                'message' => 'Media disk configuration is unavailable.',
            ], 503);
        } catch (UploadAdmissionException $exception) {
            return response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        return response()->json([
            'data' => [
                ...$path->toArray(),
                ...$capacity->toArray(),
                'fingerprint_window_bytes' => $this->uploadConfiguration->fingerprintWindowBytes,
            ],
        ]);
    }
}
