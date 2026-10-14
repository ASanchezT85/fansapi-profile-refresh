<?php

declare(strict_types=1);

namespace App\Application\Port;

use DateTimeImmutable;

/**
 * One upstream call and what came of it. Deferred jobs (account cooling down) are not attempts.
 */
final readonly class AttemptRecord
{
    /**
     * @param  string  $outcome  RefreshStatus value on success/superseded, FailureReason value on failure.
     */
    public function __construct(
        public int $profileId,
        public string $accountId,
        public int $attempt,
        public int $delivery,
        public ?string $jobId,
        public string $outcome,
        public ?int $httpStatus,
        public ?int $revision,
        public int $durationMs,
        public DateTimeImmutable $at,
    ) {}
}
