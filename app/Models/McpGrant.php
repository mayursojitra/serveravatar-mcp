<?php

namespace App\Models;

use App\Enums\PermissionLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * A user's connection to one AI client (an OAuth client), with the permission level the user
 * chose on the consent screen. The OAuth access token identifies user and client; this row says
 * what that pair may do.
 */
class McpGrant extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'oauth_client_id', 'level'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => PermissionLevel::class,
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Passport::clientModel(), 'oauth_client_id');
    }

    /** Record use at most once a minute, so a busy client does not write on every request. */
    public function markUsed(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))
            ->update(['last_used_at' => now()]);
    }
}
