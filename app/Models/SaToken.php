<?php

namespace App\Models;

use Database\Factories\SaTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's ServerAvatar API token. Encrypted at rest (the `encrypted` cast, keyed by APP_KEY),
 * hidden from every serialization, and read only through revealToken() at the moment an outbound
 * ServerAvatar call is made (FR-5).
 */
class SaToken extends Model
{
    /** @use HasFactory<SaTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id', 'token', 'last4', 'organizations', 'verified_at', 'last_error'];

    /** @var list<string> */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'organizations' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The plain token. Call this only when making a ServerAvatar request, and never log or return it. */
    public function revealToken(): string
    {
        return (string) $this->getAttribute('token');
    }

    /** @return array{last4: string, organizations: array<int, array{id: int, name: string}>, verified_at: string|null, last_error: string|null} */
    public function summary(): array
    {
        return [
            'last4' => $this->last4,
            'organizations' => $this->organizations ?? [],
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
