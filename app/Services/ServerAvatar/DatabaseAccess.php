<?php

namespace App\Services\ServerAvatar;

/**
 * Who may connect to a database user: the rules and wording shared by create_database and
 * update_database_user, so the two cannot drift apart.
 */
final class DatabaseAccess
{
    public const PREFERENCES = ['localhost', 'everywhere', 'specific_ip_addresses'];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'connection_preference' => ['nullable', 'string', 'in:'.implode(',', self::PREFERENCES)],
            'hostname' => ['nullable', 'required_if:connection_preference,specific_ip_addresses', 'array', 'max:10'],
            'hostname.*' => ['required', 'string', 'ipv4', 'not_in:0.0.0.0'],
        ];
    }

    /** @return list<string> The addresses that are sent: only for specific_ip_addresses, without duplicates. */
    public static function addresses(string $preference, mixed $hostname): array
    {
        return $preference === 'specific_ip_addresses' && is_array($hostname) ? array_values(array_unique($hostname)) : [];
    }

    /** @param  list<string>  $addresses */
    public static function describe(string $preference, array $addresses): string
    {
        return match ($preference) {
            'localhost' => 'localhost (the server itself only)',
            'everywhere' => 'everywhere (any computer: the database is exposed to the internet)',
            default => implode(', ', $addresses),
        };
    }
}
