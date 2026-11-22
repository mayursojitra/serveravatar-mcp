<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Models\ActivityLog;
use App\Services\ServerAvatar\InvalidToken;
use App\Services\ServerAvatar\TokenVault;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page where a user saves, checks and removes their one ServerAvatar token (FR-4 to FR-6).
 * The page only ever receives the last four characters, never the token (FR-5).
 */
class TokenController extends Controller
{
    public function __construct(private readonly TokenVault $vault) {}

    public function show(Request $request): Response
    {
        return Inertia::render('token/index', [
            'token' => $request->user()->saToken?->summary(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $user = $request->user();

        try {
            ['token' => $saved, 'replaced' => $replaced] = $this->vault->store($user, $validated['token']);
        } catch (InvalidToken $e) {
            ActivityLogger::log(ActivityKind::TokenVerifyFailed, ActivityLog::STATUS_FAILED, $user, ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        ActivityLogger::log($replaced ? ActivityKind::TokenReplaced : ActivityKind::TokenSaved, user: $user, fields: [
            'meta' => ['organizations' => count($saved->organizations)],
        ]);

        // A user sent here from the consent screen goes back to it.
        return redirect()->intended(route('token.show'))
            ->with('status', $replaced ? 'Token replaced. Your connected AI clients keep working.' : 'Token saved.');
    }

    public function test(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->saToken()->exists()) {
            return back()->with('error', 'Save a token first.');
        }

        try {
            $saved = $this->vault->test($user);
        } catch (InvalidToken $e) {
            ActivityLogger::log(ActivityKind::TokenTested, ActivityLog::STATUS_FAILED, $user, ['error' => $e->getMessage()]);

            return back()->with('error', $e->getMessage());
        }

        ActivityLogger::log(ActivityKind::TokenTested, user: $user, fields: [
            'meta' => ['organizations' => count($saved->organizations)],
        ]);

        return back()->with('status', 'The token works.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->saToken()->exists()) {
            return back();
        }

        $revoked = $this->vault->delete($user);

        ActivityLogger::log(ActivityKind::TokenDeleted, user: $user, fields: [
            'meta' => ['connections_revoked' => $revoked],
        ]);

        return back()->with('status', 'Token deleted. Your AI clients were disconnected; save a token and connect them again.');
    }
}
