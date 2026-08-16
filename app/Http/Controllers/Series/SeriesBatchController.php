<?php

namespace App\Http\Controllers\Series;

use App\Actions\Series\CreateOrReplaySeriesBatch;
use App\Actions\Series\RecoverSeriesBatch;
use App\Enums\SeriesBatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Series\PreviewSeriesBatchRequest;
use App\Http\Requests\Series\RecoverSeriesBatchRequest;
use App\Http\Requests\Series\StoreSeriesBatchRequest;
use App\Models\Series;
use App\Models\SeriesUploadBatch;
use App\Models\User;
use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Series\SeriesBatchPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeriesBatchController extends Controller
{
    public function preview(
        PreviewSeriesBatchRequest $request,
        Series $series,
        CreateOrReplaySeriesBatch $action,
    ): JsonResponse {
        try {
            return response()->json(['data' => $action->preview($this->user($request), $series, $request->payload())]);
        } catch (UploadAdmissionException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }
    }

    public function store(
        StoreSeriesBatchRequest $request,
        Series $series,
        CreateOrReplaySeriesBatch $action,
        SeriesBatchPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        try {
            $result = $action->execute($user, $series, $request->payload());
        } catch (UploadAdmissionException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return response()->json(
            ['data' => $presenter->present($result['batch']), 'idempotent_replay' => $result['idempotent_replay']],
            $result['idempotent_replay'] ? 200 : 201,
        );
    }

    public function index(Request $request, SeriesBatchPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $query = SeriesUploadBatch::query()
            ->whereIn('status', [
                SeriesBatchStatus::Pending,
                SeriesBatchStatus::Uploading,
                SeriesBatchStatus::Paused,
            ])
            ->latest();

        if (! $user->isAdministrator()) {
            $query->whereBelongsTo($user);
        }

        return response()->json(['data' => $query->limit(50)->get()->map(fn (SeriesUploadBatch $batch): array => $presenter->present($batch))]);
    }

    public function show(Request $request, SeriesUploadBatch $seriesUploadBatch, SeriesBatchPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeBatch($seriesUploadBatch, $user);

        return response()->json(['data' => $presenter->present($seriesUploadBatch)]);
    }

    public function recovery(
        RecoverSeriesBatchRequest $request,
        SeriesUploadBatch $seriesUploadBatch,
        RecoverSeriesBatch $action,
        SeriesBatchPresenter $presenter,
    ): JsonResponse {
        $this->authorizeBatch($seriesUploadBatch, $this->user($request));

        return response()->json([
            'data' => $presenter->present($action->execute($seriesUploadBatch, $request->items())),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function authorizeBatch(SeriesUploadBatch $batch, User $user): void
    {
        if ($batch->user_id !== $user->getKey() && ! $user->isAdministrator()) {
            throw new AuthorizationException('You do not own this Series batch.');
        }
    }
}
