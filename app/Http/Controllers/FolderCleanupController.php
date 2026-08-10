<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmFolderCleanup;
use App\Actions\PreviewFolderCleanup;
use App\Http\Requests\ConfirmFolderCleanupRequest;
use App\Models\FolderCleanup;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FolderCleanupController extends Controller
{
    public function preview(Request $request, LibraryFinding $libraryFinding, PreviewFolderCleanup $preview): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdministrator(), 403);

        try {
            $cleanup = $preview->execute($libraryFinding, $user);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['manifest_hash' => $exception->getMessage()]);
        }

        return response()->json(['data' => $cleanup]);
    }

    public function confirm(
        ConfirmFolderCleanupRequest $request,
        FolderCleanup $folderCleanup,
        ConfirmFolderCleanup $confirm,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $confirm->execute(
                $folderCleanup,
                $user,
                $request->string('manifest_hash')->value(),
                $request->boolean('cleanup_confirmed'),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['manifest_hash' => $exception->getMessage()]);
        }

        return back();
    }
}
