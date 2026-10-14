<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Why a refresh attempt did not produce a valid snapshot.
 */
enum FailureReason: string
{
    case RateLimited = 'rate_limited';       // HTTP 429
    case Timeout = 'timeout';                // no answer within the HTTP timeout
    case ConnectionFailed = 'connection_failed';
    case ServerError = 'server_error';       // HTTP 5xx
    case InvalidPayload = 'invalid_payload'; // 2xx with a body we must not store
    case NotFound = 'not_found';             // HTTP 404/410: the profile is gone
    case Rejected = 'rejected';              // other 4xx: our request/credential is wrong

    /** Transient failures may succeed if retried later; the rest will not change by retrying now. */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimited, self::Timeout, self::ConnectionFailed, self::ServerError => true,
            self::InvalidPayload, self::NotFound, self::Rejected => false,
        };
    }
}
