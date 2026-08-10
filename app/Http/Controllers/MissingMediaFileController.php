<?php

namespace App\Http\Controllers;

use App\Actions\ReconcileMissingMediaFile;
use App\Http\Requests\ReconcileMissingMediaFileRequest;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MissingMediaFileController extends Controller
{
    public function __invoke(
        ReconcileMissingMediaFileRequest $request,
        LibraryFinding $libraryFinding,
        ReconcileMissingMediaFile $reconcile,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $reconcile->execute($libraryFinding, $user, $request->boolean('removal_confirmed'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['removal_confirmed' => $exception->getMessage()]);
        }

        return back();
    }
}
