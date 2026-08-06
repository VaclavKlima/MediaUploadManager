<?php

namespace App\Http\Controllers;

use App\Actions\CompleteCredentialChange;
use App\Http\Requests\OnboardingUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresCredentialChange()) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/Onboarding', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(
        OnboardingUpdateRequest $request,
        CompleteCredentialChange $completeCredentialChange,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $completeCredentialChange->handle(
            $user,
            $request->string('password')->value(),
            $request,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your password has been replaced.']);

        return to_route('dashboard');
    }
}
