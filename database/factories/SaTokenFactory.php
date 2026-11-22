<?php

namespace Database\Factories;

use App\Models\SaToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Writes a token row directly, without calling ServerAvatar (tests only).
 *
 * @extends Factory<SaToken>
 */
class SaTokenFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $token = 'test-'.Str::random(59);

        return [
            'user_id' => User::factory(),
            'token' => $token,
            'last4' => substr($token, -4),
            'organizations' => [['id' => 1001, 'name' => 'Example Org']],
            'verified_at' => now(),
            'last_error' => null,
        ];
    }
}
