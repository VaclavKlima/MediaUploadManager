<?php

namespace App\Http\Controllers;

use App\Actions\DeleteTrackedMovie;
use App\Exceptions\MovieDeletionException;
use App\Http\Requests\DeleteMovieRequest;
use App\Http\Requests\ListMoviesRequest;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Media\MovieLibraryPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MovieLibraryController extends Controller
{
    public function index(ListMoviesRequest $request, MovieLibraryPresenter $presenter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $filters = [
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'sort' => $request->string('sort', 'newest')->value(),
        ];

        return Inertia::render('movies/Index', [
            'movies' => $presenter->paginate($user, $filters),
            'filters' => $filters,
        ]);
    }

    public function destroy(
        DeleteMovieRequest $request,
        MediaItem $mediaItem,
        DeleteTrackedMovie $deleteTrackedMovie,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $deleteTrackedMovie->execute(
                $mediaItem,
                $user,
                $request->string('confirmation_title')->value(),
            );
        } catch (MovieDeletionException $exception) {
            throw ValidationException::withMessages(['deletion' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Movie permanently deleted.']);

        return back();
    }
}
