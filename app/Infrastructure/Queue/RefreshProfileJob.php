<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshesProfile;
use Illuminate\Contracts\Cache\Repository as Cache;
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

    public const string CRASH_AFTER_WRITE = 'chaos:crash-after-write';

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

    public function handle(RefreshesProfile $refresher, Cache $cache): void
    {
        $refresher->execute(new RefreshCommand(
            $this->profileId,
            $this->attempt,
            $this->job?->uuid(),
            $this->job?->attempts() ?? 1,
            $this->cycleStartedAt,
        ));

        // Crash demo only (local, explicitly enabled, one shot): the worker process dies after the
        // database write and before the queue acknowledges the job. Redis then redelivers it after
        // retry_after. See `php artisan refresh:crash-demo`.
        // The Redis cache store returns numbers as strings, hence the string comparison.
        // forget() is the one-shot guard: only the process whose DEL removed the key crashes.
        $crashFor = config()->boolean('refresh.chaos.enabled') ? $cache->get(self::CRASH_AFTER_WRITE) : null;
        if (is_scalar($crashFor) && (string) $crashFor === (string) $this->profileId && $cache->forget(self::CRASH_AFTER_WRITE)) {
            posix_kill((int) getmypid(), SIGKILL);
        }
    }
}
