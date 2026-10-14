<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshesProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Thin driving adapter: turns a queue delivery into a RefreshCommand. All decisions live in the
 * application layer. Upstream retries are new jobs queued by RefreshProfile, so $tries here only
 * covers redelivery after a worker crash/timeout or an unexpected exception (DB down...).
 */
final class RefreshProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /** @var list<int> Seconds between queue-level redeliveries after an unexpected exception. */
    public array $backoff = [5, 30];

    public function __construct(
        public readonly int $profileId,
        public readonly int $attempt = 1,
        public readonly ?int $cycleStartedAt = null,
    ) {
        $this->onQueue(config()->string('refresh.queue.name'));
        $this->tries = config()->integer('refresh.queue.job_deliveries');
        $this->timeout = config()->integer('refresh.queue.job_timeout_seconds');
    }

    /**
     * One worker per profile at a time. A second delivery of the same profile while the first one
     * still runs is dropped (dontRelease): the running one will finish the work. The lock expires
     * after the job timeout so a crashed worker cannot block the profile forever.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('profile:'.$this->profileId))
                ->dontRelease()
                ->expireAfter(config()->integer('refresh.queue.overlap_lock_seconds')),
        ];
    }

    public function handle(RefreshesProfile $refresher): void
    {
        $refresher->execute(new RefreshCommand(
            $this->profileId,
            $this->attempt,
            $this->job?->uuid(),
            $this->job?->attempts() ?? 1,
            $this->cycleStartedAt,
        ));
    }
}
