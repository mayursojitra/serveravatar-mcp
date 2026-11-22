<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per event: sign-ins, admin actions and (from M2) every MCP request.
 * Rows only ever hold redacted arguments, never ServerAvatar tokens or passwords.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DENIED = 'denied';

    /** Written only through App\Support\ActivityLogger. */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'args_redacted' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'time' => $this->created_at?->toIso8601String(),
            'user_email' => $this->user_email,
            'user_role' => $this->user_role,
            'kind' => $this->kind,
            'status' => $this->status,
            'client_name' => $this->client_name,
            'method' => $this->method,
            'tool' => $this->tool,
            'ip' => $this->ip,
            'args_redacted' => $this->args_redacted,
            'meta' => $this->meta,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
