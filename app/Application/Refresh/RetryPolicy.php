<?php

declare(strict_types=1);

namespace App\Application\Refresh;

use App\Domain\Profile\FailureReason;
use DateTimeImmutable;
use Random\Randomizer;

/**
 * How long to wait before trying again, per failure type. Pure: randomness is injected
 * (seed it in tests), so every decision is reproducible.
 *
 *  - 429 without Retry-After: randomized exponential delay in [d, 2d], d = base * 2^(attempt-1).
 *    Randomized so a burst of rate-limited jobs does not come back at the same instant.
 *  - 429 with Retry-After: the upstream's value (capped).
 *  - timeouts, connection errors, 5xx: exponential backoff with "equal jitter" (d/2 + random d/2).
 *  - invalid payload, 404/410, other 4xx: never retried now; retrying cannot change the answer.
 *  - attempt limit: after maxAttempts the cycle ends and the profile is retried much later.
 */
final readonly class RetryPolicy
{
    public function __construct(
        private RetrySettings $settings,
        private Randomizer $random,
    ) {}

    /**
     * @return int|null Seconds until the next attempt, or null when this cycle must stop.
     */
    public function retryDelay(FailureReason $reason, int $attempt, ?int $retryAfterSeconds = null): ?int
    {
        if (! $reason->isTransient() || $attempt >= $this->settings->maxAttempts) {
            return null;
        }

        return $reason === FailureReason::RateLimited
            ? $this->rateLimitDelay($attempt, $retryAfterSeconds)
            : $this->backoff($attempt);
    }

    public function rateLimitDelay(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null) {
            return $this->cap(max(1, $retryAfterSeconds));
        }

        $base = $this->exponential($this->settings->rateLimitBaseSeconds, $attempt);

        return $this->cap($this->random->getInt($base, 2 * $base));
    }

    /** Small random spread for jobs deferred behind a cooldown, so they do not all resume together. */
    public function deferralJitter(): int
    {
        return $this->random->getInt(0, $this->settings->deferralJitterSeconds);
    }

    public function nextRefreshAfterGivingUp(FailureReason $reason, DateTimeImmutable $now): DateTimeImmutable
    {
        $seconds = $reason->isTransient()
            ? $this->settings->exhaustedRetryDelaySeconds
            : $this->settings->permanentFailureDelaySeconds;

        return $now->modify(sprintf('+%d seconds', $seconds));
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->cap($this->exponential($this->settings->backoffBaseSeconds, $attempt));
        $half = intdiv($delay, 2);

        return max(1, $half + $this->random->getInt(0, $delay - $half));
    }

    private function exponential(int $base, int $attempt): int
    {
        $exponent = min(max($attempt - 1, 0), 16);

        return max(1, $base) * (2 ** $exponent);
    }

    private function cap(int $seconds): int
    {
        return min($seconds, $this->settings->maxDelaySeconds);
    }
}
