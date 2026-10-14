<?php

declare(strict_types=1);

namespace App\Application\Refresh;

/**
 * One attempt to refresh one profile. Carries ids only, never payloads, so queued jobs stay tiny.
 */
final readonly class RefreshCommand
{
    /**
     * @param  int  $attempt  Upstream attempt number for this refresh cycle (1 = first try).
     * @param  string|null  $jobId  Queue job uuid, for log correlation only.
     * @param  int  $delivery  How many times the queue delivered this job (>1 means redelivery after a crash/timeout).
     */
    public function __construct(
        public int $profileId,
        public int $attempt = 1,
        public ?string $jobId = null,
        public int $delivery = 1,
    ) {}
}
