<?php

namespace App\Support;

/**
 * Makes tool arguments safe to store in the activity log (FR-17): secrets are replaced, long text
 * is cut, and the structure is bounded. The log never holds a token or a password.
 */
class Redactor
{
    private const SENSITIVE = '/(token|secret|passw|authorization|api[_-]?key|credential|cookie|private[_-]?key|bearer)/i';

    private const MAX_STRING = 500;

    private const MAX_ITEMS = 50;

    private const MAX_DEPTH = 6;

    /**
     * Removes values that look like secrets from free text: a cron command, a supervisor command or a
     * log line. It cannot catch everything (a bare password in an unusual place stays), so it is a
     * safety net and not a guarantee: tools also cap how much text they return.
     */
    public static function redactText(string $text): string
    {
        $patterns = [
            // --password=abc, --token abc, -password abc, --api-key=abc
            '/(--?(?:password|passwd|pass|pwd|token|secret|api[-_]?key|auth[-_]?token|access[-_]?key)(?:=|\s+))(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1[redacted]',
            // mysql -pSECRET (the password is attached to the flag)
            '/(\s-p)(?=\S)(?:"[^"]*"|\'[^\']*\'|\S+)/' => '$1[redacted]',
            // Authorization: Bearer abc
            '/(authorization:\s*(?:bearer|basic|token)\s+)\S+/i' => '$1[redacted]',
            // https://user:password@host
            '#(://[^:/@\s]+:)[^@\s]+(@)#' => '$1[redacted]$2',
            // DB_PASSWORD=abc, ?api_key=abc, SECRET_TOKEN=abc
            '/\b([A-Za-z0-9_]*(?:password|passwd|secret|token|api_?key|access_?key)[A-Za-z0-9_]*=)[^&\s\'"]+/i' => '$1[redacted]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[too deep]';
        }

        if (is_array($value)) {
            $out = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if (++$count > self::MAX_ITEMS) {
                    $out['…'] = (count($value) - self::MAX_ITEMS).' more items';
                    break;
                }

                $out[$key] = is_string($key) && preg_match(self::SENSITIVE, $key)
                    ? '[redacted]'
                    : self::redact($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            // A secret can sit inside a value whose key looks harmless (a cron "command" with --password=...).
            $value = self::redactText($value);

            if (mb_strlen($value) > self::MAX_STRING) {
                return mb_substr($value, 0, self::MAX_STRING).'…[cut '.(mb_strlen($value) - self::MAX_STRING).' characters]';
            }
        }

        if (is_object($value)) {
            return self::redact((array) $value, $depth + 1);
        }

        return $value;
    }
}
