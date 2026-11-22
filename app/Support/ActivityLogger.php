<?php

namespace App\Support;

use App\Enums\ActivityKind;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Str;

class ActivityLogger
{
    /**
     * Record one event. The caller passes only data that is safe to store: no passwords,
     * no ServerAvatar tokens.
     *
     * Optional $fields: email (when there is no user), meta, error, client_name, method, tool,
     * args_redacted, duration_ms, mcp_grant_id.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function log(ActivityKind|string $kind, string $status = ActivityLog::STATUS_OK, ?User $user = null, array $fields = []): ActivityLog
    {
        $request = request();

        return ActivityLog::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email ?? $fields['email'] ?? null,
            'user_role' => $user?->role,
            'kind' => $kind instanceof ActivityKind ? $kind->value : $kind,
            'status' => $status,
            'mcp_grant_id' => $fields['mcp_grant_id'] ?? null,
            'client_name' => isset($fields['client_name']) ? Str::limit((string) $fields['client_name'], 255, '') : null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ? Str::limit($request->userAgent(), 512, '') : null,
            'method' => $fields['method'] ?? null,
            'tool' => $fields['tool'] ?? null,
            'args_redacted' => $fields['args_redacted'] ?? null,
            'meta' => $fields['meta'] ?? null,
            'error' => isset($fields['error']) ? Str::limit((string) $fields['error'], 2000, '') : null,
            'duration_ms' => $fields['duration_ms'] ?? null,
        ]);
    }
}
