<?php

namespace App\Services\ServerAvatar\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Something went wrong talking to ServerAvatar. The message is written for the person or AI model
 * that reads it: it says what happened and what to do, and never contains the token.
 */
class ServerAvatarException extends RuntimeException
{
    /** @param  array<string, mixed>  $errors */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    /** Builds the right exception from a failed response. */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $apiMessage = self::clean($response->json('message'));
        $errors = is_array($response->json('errors')) ? $response->json('errors') : [];
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

        return match (true) {
            $status === 401 => new AuthenticationFailed('ServerAvatar rejected the saved API token (it may have expired or been revoked). Replace it on the ServerAvatar token page.', $status),
            $status === 403 => new PermissionDenied('ServerAvatar refused this: the token\'s user does not have permission for it.'.($apiMessage !== '' ? " ({$apiMessage})" : ''), $status),
            $status === 404 => new NotFound('ServerAvatar could not find that. Check the organization, server or application id.', $status),
            $status === 422 => new ValidationFailed('ServerAvatar rejected the request: '.self::firstError($errors, $apiMessage), $status, $errors),
            $status === 429 => new RateLimited('ServerAvatar\'s rate limit was reached. Wait a minute and try again.', $status, [], $retryAfter),
            $status >= 500 => new ServerError("ServerAvatar had a problem (HTTP {$status}).".($apiMessage !== '' ? " It said: {$apiMessage}" : '').' Try again shortly.', $status),
            default => new self("ServerAvatar answered with an unexpected status (HTTP {$status}).", $status),
        };
    }

    /** @param  array<string, mixed>  $errors */
    private static function firstError(array $errors, string $fallback): string
    {
        foreach ($errors as $messages) {
            $first = is_array($messages) ? reset($messages) : $messages;

            if (is_string($first) && $first !== '') {
                return self::clean($first);
            }
        }

        return $fallback !== '' ? $fallback : 'invalid input.';
    }

    private static function clean(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 300) : '';
    }
}
