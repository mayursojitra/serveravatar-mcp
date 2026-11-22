<?php

namespace App\Services\ServerAvatar;

/** Cleans up what a person pastes: ServerAvatar's own examples show a token as "Bearer <token>". */
final class TokenNormalizer
{
    public static function normalize(string $raw): string
    {
        $token = trim($raw);
        $token = trim($token, "\"'` \t\r\n");
        $token = (string) preg_replace('/^bearer(\s+|$)/i', '', $token);

        return trim($token);
    }
}
