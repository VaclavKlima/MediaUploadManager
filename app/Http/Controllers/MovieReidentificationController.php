<?php

namespace App\Http\Controllers;

use App\Actions\PreviewMovieReidentification;
use App\Actions\ReidentifyMovie;
use App\Exceptions\MovieReidentificationException;
use App\Http\Requests\PreviewMovieReidentificationRequest;
use App\Http\Requests\ReidentifyMovieRequest;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Tmdb\TmdbClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MovieReidentificationController extends Controller
{
    public function __construct(private readonly TmdbClient $tmdb) {}

    public function preview(
        PreviewMovieReidentificationRequest $request,
        MediaItem $mediaItem,
        PreviewMovieReidentification $preview,
    ): JsonResponse {
        return response()->json([
            'data' => $preview->execute(
                $mediaItem,
                $this->tmdb->movie($request->integer('tmdb_id')),
            ),
        ]);
    }

    public function store(
        ReidentifyMovieRequest $request,
        MediaItem $mediaItem,
        ReidentifyMovie $reidentifyMovie,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $reidentifyMovie->execute(
                $mediaItem,
                $user,
                $this->tmdb->movie($request->integer('tmdb_id')),
            );
        } catch (MovieReidentificationException $exception) {
            throw ValidationException::withMessages(['reidentification' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Movie identification changed.']);

        return back();
    }
}
