<?php

declare(strict_types=1);

namespace App\Application\Refresh;

/**
 * One attempt to refresh one profile. Carries ids only, never payloads, so queued jobs stay tiny.
 */
final readonly class RefreshCommand
{
    /**
     * @param  int  $attempt  Upstream attempt number within this refresh cycle (1 = first try).
     * @param  string|null  $jobId  Queue job uuid, for log correlation only.
     * @param  int  $delivery  How many times the queue delivered this job (>1 = redelivery after a crash/timeout).
     * @param  int|null  $cycleStartedAt  Unix time the cycle was first queued; kept across retries to measure waiting age.
     */
    public function __construct(
        public int $profileId,
        public int $attempt = 1,
        public ?string $jobId = null,
        public int $delivery = 1,
        public ?int $cycleStartedAt = null,
    ) {}

    public function nextAttempt(): self
    {
        return new self($this->profileId, $this->attempt + 1, cycleStartedAt: $this->cycleStartedAt);
    }

    /** Re-queue without spending an attempt (nothing was sent upstream). */
    public function sameAttempt(): self
    {
        return new self($this->profileId, $this->attempt, cycleStartedAt: $this->cycleStartedAt);
    }
}
