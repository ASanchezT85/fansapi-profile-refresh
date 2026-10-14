<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Profile\FailureReason;
use DateTimeImmutable;

final readonly class RefreshFailure
{
    /**
     * @param  bool  $cycleFinished  true when no retry is scheduled: the profile stops being pending.
     * @param  DateTimeImmutable|null  $nextRefreshAt  When to try again, only when the cycle finished.
     */
    public function __construct(
        public FailureReason $reason,
        public string $detail,
        public DateTimeImmutable $failedAt,
        public bool $cycleFinished,
        public ?DateTimeImmutable $nextRefreshAt = null,
    ) {}
}
