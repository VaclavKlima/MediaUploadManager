<?php

namespace App\Http\Controllers\Series;

use App\Actions\Series\PreviewEpisodeRename;
use App\Actions\Series\RenameEpisode;
use App\Exceptions\SeriesOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Series\PreviewEpisodeRenameRequest;
use App\Http\Requests\Series\RenameEpisodeRequest;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EpisodeRenameController extends Controller
{
    public function preview(
        PreviewEpisodeRenameRequest $request,
        Series $series,
        SeriesSeason $season,
        SeriesEpisode $episode,
        PreviewEpisodeRename $preview,
    ): JsonResponse {
        return response()->json(['data' => $preview->execute(
            $episode,
            $this->user($request),
            $request->filled('custom_name') ? $request->string('custom_name')->value() : null,
        )]);
    }

    public function update(
        RenameEpisodeRequest $request,
        Series $series,
        SeriesSeason $season,
        SeriesEpisode $episode,
        RenameEpisode $rename,
    ): RedirectResponse {
        try {
            $rename->execute(
                $episode,
                $this->user($request),
                $request->filled('custom_name') ? $request->string('custom_name')->value() : null,
            );
        } catch (SeriesOperationException $exception) {
            abort_if($exception->httpStatus === 403, 403, $exception->getMessage());
            throw ValidationException::withMessages(['rename' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Episode title updated.']);

        return back();
    }

    private function user(PreviewEpisodeRenameRequest|RenameEpisodeRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
