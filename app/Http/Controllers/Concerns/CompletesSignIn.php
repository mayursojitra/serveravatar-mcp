<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ActivityKind;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a user is actually signed in. The password step and the two-factor step both end here,
 * so a session exists only after every factor the user has was checked.
 */
trait CompletesSignIn
{
    /** @param  array<string, mixed>  $meta */
    protected function completeSignIn(Request $request, User $user, bool $remember, array $meta = []): RedirectResponse
    {
        Auth::guard('web')->login($user, $remember);

        $request->session()->regenerate();

        ActivityLogger::log(ActivityKind::AuthLogin, user: $user, fields: $meta === [] ? [] : ['meta' => $meta]);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
