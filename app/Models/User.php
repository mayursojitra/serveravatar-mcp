<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    /**
     * `role`, `disabled` and `must_change_password` are deliberately not mass assignable:
     * no request can set them. Admins are made only by the `serveravatar:create-admin` command.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => self::ROLE_USER,
        'disabled' => false,
        'must_change_password' => false,
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'disabled' => 'boolean',
            'must_change_password' => 'boolean',
            // Encrypted with APP_KEY. Rotating APP_KEY makes every saved secret unreadable, so
            // two-factor sign-in must be reset for everyone afterwards (see docs/self-hosting.md).
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** True once the user confirmed their authenticator app. A setup that was started but not confirmed does not count. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** @return HasMany<TwoFactorRecoveryCode, $this> */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    /** @return HasOne<SaToken, $this> */
    public function saToken(): HasOne
    {
        return $this->hasOne(SaToken::class);
    }

    /** @return HasMany<McpGrant, $this> */
    public function mcpGrants(): HasMany
    {
        return $this->hasMany(McpGrant::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
