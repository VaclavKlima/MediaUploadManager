<?php

namespace App\Http\Controllers\Series;

use App\Http\Controllers\Controller;
use App\Http\Requests\Series\ListSeriesRequest;
use App\Models\Series;
use App\Models\User;
use App\Support\Series\SeriesDetailsPresenter;
use Inertia\Inertia;
use Inertia\Response;

class SeriesDetailsController extends Controller
{
    public function __invoke(
        ListSeriesRequest $request,
        Series $series,
        SeriesDetailsPresenter $presenter,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('series/Show', [
            'show' => $presenter->present(
                $series,
                $user,
                $request->filled('season') ? $request->integer('season') : null,
            ),
        ]);
    }
}
