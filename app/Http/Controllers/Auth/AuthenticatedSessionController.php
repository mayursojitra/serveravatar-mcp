<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\CompletesSignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    use CompletesSignIn;

    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        // The password is right, but nobody is signed in yet: a user with two-factor sign-in must pass the
        // challenge first. Only the user's id is kept in the session meanwhile, and only for a few minutes.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'at' => now()->getTimestamp(),
                'failures' => 0,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeSignIn($request, $user, $request->boolean('remember'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
