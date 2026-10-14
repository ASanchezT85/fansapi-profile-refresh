<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Profile\FailureReason;
use RuntimeException;

/**
 * The upstream request failed. Messages must not contain secrets (tokens, auth headers).
 */
final class UpstreamFailure extends RuntimeException
{
    private function __construct(
        public readonly FailureReason $reason,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(?int $retryAfterSeconds): self
    {
        $hint = $retryAfterSeconds === null ? 'without Retry-After' : sprintf('Retry-After %ds', $retryAfterSeconds);

        return new self(FailureReason::RateLimited, 'HTTP 429 '.$hint.'.', 429, $retryAfterSeconds);
    }

    public static function timeout(): self
    {
        return new self(FailureReason::Timeout, 'No response within the HTTP timeout.');
    }

    public static function connectionFailed(): self
    {
        return new self(FailureReason::ConnectionFailed, 'Could not connect to the upstream.');
    }

    public static function serverError(int $status, int $bodyBytes): self
    {
        return new self(FailureReason::ServerError, sprintf('HTTP %d with a %d-byte body.', $status, $bodyBytes), $status);
    }

    public static function notFound(int $status): self
    {
        return new self(FailureReason::NotFound, sprintf('HTTP %d: profile not found upstream.', $status), $status);
    }

    public static function rejected(int $status): self
    {
        return new self(FailureReason::Rejected, sprintf('HTTP %d: request rejected by the upstream.', $status), $status);
    }
}
