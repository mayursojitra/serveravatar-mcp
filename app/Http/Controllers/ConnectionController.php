<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Enums\PermissionLevel;
use App\Models\McpGrant;
use App\Services\Mcp\ConnectionRevoker;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** FR-12: a user's own connected AI clients. Never shows or touches another user's. */
class ConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $connections = $request->user()->mcpGrants()
            ->with('client')
            ->latest('id')
            ->get()
            ->map(fn (McpGrant $grant) => [
                'id' => $grant->id,
                'client_name' => $grant->client?->name ?? 'Unknown client',
                'level' => $grant->level->value,
                'connected_at' => $grant->created_at?->toIso8601String(),
                'last_used_at' => $grant->last_used_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('connections/index', [
            'connections' => $connections,
            'levels' => PermissionLevel::options(),
            'mcpUrl' => url('/mcp'),
            'hasToken' => $request->user()->saToken()->exists(),
        ]);
    }

    /** Change the permission level; it applies to the next request, with no reconnect. */
    public function update(Request $request, int $grant): RedirectResponse
    {
        $connection = $request->user()->mcpGrants()->with('client')->findOrFail($grant);

        $validated = $request->validate([
            'level' => ['required', Rule::enum(PermissionLevel::class)],
        ]);

        $from = $connection->level;
        $connection->update(['level' => $validated['level']]);

        if ($from !== $connection->level) {
            ActivityLogger::log(ActivityKind::McpConnectionLevelChanged, user: $request->user(), fields: [
                'mcp_grant_id' => $connection->id,
                'client_name' => $connection->client?->name,
                'meta' => ['from' => $from->value, 'to' => $connection->level->value],
            ]);
        }

        return back()->with('status', 'Permission level updated.');
    }

    /** Revoke: the tokens die at once and the client must be connected again. */
    public function destroy(Request $request, ConnectionRevoker $revoker, int $grant): RedirectResponse
    {
        $connection = $request->user()->mcpGrants()->with('client')->findOrFail($grant);

        $clientName = $connection->client?->name;
        $grantId = $connection->id;

        $revoker->revokeGrant($connection);

        ActivityLogger::log(ActivityKind::McpConnectionRevoked, user: $request->user(), fields: [
            'client_name' => $clientName,
            'meta' => ['grant_id' => $grantId, 'by' => 'user'],
        ]);

        return back()->with('status', 'Connection revoked.');
    }
}
