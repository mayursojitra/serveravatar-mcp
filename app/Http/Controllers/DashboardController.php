<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Models\ActivityLog;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'recent' => ActivityLog::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (ActivityLog $log) => $log->toRow())
                ->values(),
            'token' => $user->saToken?->summary(),
            'usage' => $this->usage(ActivityLog::query()->where('user_id', $user->id), McpGrant::where('user_id', $user->id)),
            'stats' => $user->isAdmin() ? [
                'users' => User::count(),
                'disabled' => User::where('disabled', true)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
                'everyone' => $this->usage(ActivityLog::query(), McpGrant::query()),
            ] : null,
        ]);
    }

    /**
     * What the AI clients have done in the last 24 hours, and how many are connected.
     *
     * @param  Builder<ActivityLog>  $activity
     * @param  Builder<McpGrant>  $grants
     * @return array{calls_24h: int, failed_24h: int, denied_24h: int, connections: int, last_call_at: string|null}
     */
    private function usage(Builder $activity, Builder $grants): array
    {
        $calls = $activity->where('kind', ActivityKind::McpToolCall->value);
        $since = now()->subDay();
        $recent = (clone $calls)->where('created_at', '>=', $since);

        return [
            'calls_24h' => (clone $recent)->count(),
            'failed_24h' => (clone $recent)->where('status', ActivityLog::STATUS_FAILED)->count(),
            'denied_24h' => (clone $recent)->where('status', ActivityLog::STATUS_DENIED)->count(),
            'connections' => $grants->count(),
            'last_call_at' => $calls->latest('id')->value('created_at')?->toIso8601String(),
        ];
    }
}
