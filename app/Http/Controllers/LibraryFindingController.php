<?php

namespace App\Http\Controllers;

use App\Actions\DeleteLibraryFinding;
use App\Actions\IdentifyLibraryFinding;
use App\Actions\QueueLibraryFindingImport;
use App\Actions\QueueLibraryFindingRestore;
use App\Enums\SeriesCategory;
use App\Http\Requests\DeleteLibraryFindingRequest;
use App\Http\Requests\IdentifyAndImportLibraryFindingRequest;
use App\Http\Requests\IdentifyLibraryFindingRequest;
use App\Http\Requests\ImportLibraryFindingsRequest;
use App\Http\Requests\PreviewLibraryFindingIdentityRequest;
use App\Http\Requests\QueueLibraryFindingImportRequest;
use App\Http\Requests\QueueLibraryFindingRestoreRequest;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class LibraryFindingController extends Controller
{
    public function previewIdentity(
        PreviewLibraryFindingIdentityRequest $request,
        LibraryFinding $libraryFinding,
        IdentifyLibraryFinding $identify,
    ): JsonResponse {
        try {
            $decision = $identify->preview(
                $libraryFinding,
                $request->integer('tmdb_id'),
                $request->filled('category') ? SeriesCategory::from($request->string('category')->value()) : null,
                $request->filled('season_number') ? $request->integer('season_number') : null,
                $request->filled('episode_number') ? $request->integer('episode_number') : null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['tmdb_id' => $exception->getMessage()]);
        }

        return response()->json([
            'data' => $decision->toArray(
                $libraryFinding->disk_id,
                $libraryFinding->relative_path,
                $libraryFinding->source_filename,
                $libraryFinding->size_bytes,
            ),
        ]);
    }

    public function identify(
        IdentifyLibraryFindingRequest $request,
        LibraryFinding $libraryFinding,
        IdentifyLibraryFinding $identify,
    ): RedirectResponse {
        try {
            $identify->execute(
                $libraryFinding,
                $request->integer('tmdb_id'),
                $request->filled('category') ? SeriesCategory::from($request->string('category')->value()) : null,
                $request->filled('season_number') ? $request->integer('season_number') : null,
                $request->filled('episode_number') ? $request->integer('episode_number') : null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['tmdb_id' => $exception->getMessage()]);
        }

        return back();
    }

    public function identifyAndImport(
        IdentifyAndImportLibraryFindingRequest $request,
        LibraryFinding $libraryFinding,
        IdentifyLibraryFinding $identify,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $decision = $identify->identifyAndQueueImport(
                $libraryFinding,
                $request->integer('tmdb_id'),
                $request->string('destination_relative_path')->value(),
                $user,
                $request->filled('category') ? SeriesCategory::from($request->string('category')->value()) : null,
                $request->filled('season_number') ? $request->integer('season_number') : null,
                $request->filled('episode_number') ? $request->integer('episode_number') : null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['tmdb_id' => $exception->getMessage()]);
        }

        if (! $decision->canImport()) {
            throw ValidationException::withMessages([
                'tmdb_id' => $decision->blockerMessage ?? 'This identity cannot be imported.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import queued.']);

        return back();
    }

    public function queueImport(
        QueueLibraryFindingImportRequest $request,
        LibraryFinding $libraryFinding,
        QueueLibraryFindingImport $queueImport,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $queueImport->execute($libraryFinding, $user);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['finding' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import queued.']);

        return back();
    }

    public function restore(
        QueueLibraryFindingRestoreRequest $request,
        LibraryFinding $libraryFinding,
        QueueLibraryFindingRestore $queueRestore,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $queueRestore->execute($libraryFinding, $user);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['finding' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Restore queued.']);

        return back();
    }

    public function importMany(
        ImportLibraryFindingsRequest $request,
        QueueLibraryFindingImport $queueImport,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        foreach ($request->findingIds() as $findingId) {
            $finding = LibraryFinding::query()->findOrFail($findingId);
            $queueImport->execute($finding, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Selected imports queued.']);

        return back();
    }

    public function destroy(
        DeleteLibraryFindingRequest $request,
        LibraryFinding $libraryFinding,
        DeleteLibraryFinding $deletion,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $deletion->confirm($libraryFinding, $user, $request->boolean('deletion_confirmed'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['deletion_confirmed' => $exception->getMessage()]);
        }

        return back();
    }
}
