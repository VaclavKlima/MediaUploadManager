<?php

namespace App\Http\Controllers\Series;

use App\Actions\Series\DeleteSeriesMedia;
use App\Exceptions\SeriesOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Series\DeleteSeriesMediaRequest;
use App\Http\Requests\Series\DeleteSeriesRequest;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SeriesMediaDeletionController extends Controller
{
    public function episode(
        DeleteSeriesMediaRequest $request,
        Series $series,
        SeriesSeason $season,
        SeriesEpisode $episode,
        DeleteSeriesMedia $delete,
    ): RedirectResponse {
        $this->execute($delete, $series, 'episode', $episode->id, $this->user($request), $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Episode media deleted.']);

        return back();
    }

    public function season(
        DeleteSeriesMediaRequest $request,
        Series $series,
        SeriesSeason $season,
        DeleteSeriesMedia $delete,
    ): RedirectResponse {
        $this->execute($delete, $series, 'season', $season->id, $this->user($request), $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Season media deleted.']);

        return back();
    }

    public function series(
        DeleteSeriesRequest $request,
        Series $series,
        DeleteSeriesMedia $delete,
    ): RedirectResponse {
        try {
            $delete->execute(
                $series,
                'series',
                $series->id,
                $this->user($request),
                $request->boolean('deletion_confirmed'),
                $request->string('confirmation_name')->value(),
            );
        } catch (SeriesOperationException $exception) {
            abort_if($exception->httpStatus === 403, 403, $exception->getMessage());
            throw ValidationException::withMessages(['deletion' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Show permanently deleted.']);

        return to_route('series.index');
    }

    private function execute(
        DeleteSeriesMedia $delete,
        Series $series,
        string $scope,
        int $scopeId,
        User $user,
        DeleteSeriesMediaRequest $request,
    ): void {
        try {
            $delete->execute($series, $scope, $scopeId, $user, $request->boolean('deletion_confirmed'));
        } catch (SeriesOperationException $exception) {
            abort_if($exception->httpStatus === 403, 403, $exception->getMessage());
            throw ValidationException::withMessages(['deletion' => $exception->getMessage()]);
        }
    }

    private function user(DeleteSeriesMediaRequest|DeleteSeriesRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
