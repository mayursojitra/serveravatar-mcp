<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One single-use recovery code of a user. Only its keyed hash is stored (see TwoFactor). */
class TwoFactorRecoveryCode extends Model
{
    /** @var list<string> */
    protected $fillable = ['code_hash'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
