<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Media\OperationalDashboardPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OperationalDashboardPresenter $presenter): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return Inertia::render('Dashboard', [
            'uploadOverview' => $presenter->uploadOverview($user),
            'diskOverview' => Inertia::defer(
                fn (): array => $presenter->diskOverview(),
                rescue: true,
            ),
        ]);
    }
}
