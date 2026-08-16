<?php

namespace App\Http\Controllers\Series;

use App\Http\Controllers\Controller;
use App\Http\Requests\Series\ListSeriesRequest;
use App\Models\User;
use App\Support\Media\UploadConfiguration;
use App\Support\Series\SeriesCatalogPresenter;
use Inertia\Inertia;
use Inertia\Response;

class SeriesCatalogController extends Controller
{
    public function index(ListSeriesRequest $request, SeriesCatalogPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $filters = [
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'sort' => $request->string('sort', 'recent')->value(),
        ];

        return Inertia::render('series/Index', [
            'series' => $presenter->paginate($user, $filters),
            'filters' => $filters,
        ]);
    }

    public function upload(
        UploadConfiguration $configuration,
    ): Response {
        return Inertia::render('series/Upload', [
            'fingerprintWindowBytes' => $configuration->fingerprintWindowBytes,
        ]);
    }
}
