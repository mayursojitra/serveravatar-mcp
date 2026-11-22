<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountAccess;
use App\Services\Mcp\ConnectionRevoker;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admins create and manage normal users here. They can never create, promote, demote, change or
 * delete an admin: admins exist only because an operator ran `serveravatar:create-admin`.
 */
class UserController extends Controller
{
    public function __construct(private readonly ConnectionRevoker $revoker, private readonly AccountAccess $access) {}

    public function index(): Response
    {
        $users = User::query()
            ->withExists('saToken')
            ->orderByRaw('role = ? desc', [User::ROLE_ADMIN])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'disabled' => $u->disabled,
                'must_change_password' => $u->must_change_password,
                // Whether a token is saved. Admins never see the token itself, nor its last characters (FR-3).
                'has_token' => (bool) $u->sa_token_exists,
                'created_at' => $u->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/users', ['users' => $users]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        // Only these fields are read. Anything else in the request, including "role", is ignored.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = new User(Arr::only($data, ['name', 'email', 'password']));
        $user->must_change_password = true;
        $user->save();

        ActivityLogger::log(ActivityKind::AdminUserCreated, user: $request->user(), fields: [
            'meta' => ['target_user_id' => $user->id, 'target_email' => $user->email],
        ]);

        return back()->with('status', 'User created. They must set a new password at first sign-in.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'disabled' => ['required', 'boolean'],
        ]);

        $wasDisabled = $user->disabled;

        $user->name = $data['name'];
        $user->disabled = $data['disabled'];
        $user->save();

        $kind = match (true) {
            $user->disabled && ! $wasDisabled => ActivityKind::AdminUserDisabled,
            ! $user->disabled && $wasDisabled => ActivityKind::AdminUserEnabled,
            default => ActivityKind::AdminUserUpdated,
        };

        $meta = ['target_user_id' => $user->id, 'target_email' => $user->email];

        // Disabling cuts off the user's sessions and every connected AI client at once.
        if ($user->disabled) {
            $this->signOut($user);
            $meta['connections_revoked'] = $this->revoker->revokeAllForUser($user);
        }

        ActivityLogger::log($kind, user: $request->user(), fields: ['meta' => $meta]);

        return back()->with('status', 'User updated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($user);

        $data = $request->validate([
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => true,
        ])->save();

        $this->signOut($user);

        // A reset often means the old password was exposed: their AI clients are disconnected too.
        $revoked = $this->revoker->revokeAllForUser($user);

        ActivityLogger::log(ActivityKind::AdminPasswordReset, user: $request->user(), fields: [
            'meta' => ['target_user_id' => $user->id, 'target_email' => $user->email, 'connections_revoked' => $revoked],
        ]);

        return back()->with('status', 'Password reset. They must set a new one at next sign-in, and their connected AI clients were disconnected.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($user);

        $meta = ['target_user_id' => $user->id, 'target_email' => $user->email];

        // Revoke first: oauth_access_tokens.user_id has no foreign key, so deleting the user alone
        // would leave valid tokens behind.
        $this->signOut($user);
        $meta['connections_revoked'] = $this->revoker->revokeAllForUser($user);
        $user->delete();

        ActivityLogger::log(ActivityKind::AdminUserDeleted, user: $request->user(), fields: ['meta' => $meta]);

        return back()->with('status', 'User deleted.');
    }

    private function ensureManageable(User $user): void
    {
        abort_if($user->isAdmin(), 403, 'Admin accounts can only be managed from the command line.');
    }

    /** Ends every session of the user at once, so a disable or reset takes effect immediately. */
    private function signOut(User $user): void
    {
        $this->access->endSessions($user);
    }
}
