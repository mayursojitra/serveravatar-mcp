<?php

namespace App\Support;

class TrustedProxies
{
    /**
     * Parse the TRUSTED_PROXIES setting: empty trusts none, "*" trusts every proxy,
     * otherwise a comma-separated list of IPs or CIDR ranges.
     *
     * @return array<int, string>|string
     */
    public static function parse(?string $value): array|string
    {
        $value = trim((string) $value);

        return match (true) {
            $value === '' => [],
            $value === '*' => '*',
            default => array_values(array_filter(array_map('trim', explode(',', $value)))),
        };
    }
}
