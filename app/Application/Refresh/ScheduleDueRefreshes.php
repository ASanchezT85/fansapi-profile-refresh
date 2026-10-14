<?php

declare(strict_types=1);

namespace App\Application\Refresh;

use App\Application\Port\ProfileRepository;
use App\Application\Port\RefreshQueue;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues one refresh per due profile. Claiming marks the profile pending in the same statement,
 * so running this twice (or on two schedulers) never queues the same profile twice.
 * A pending mark older than the pending timeout is reclaimed: its job was lost (e.g. Redis flushed).
 */
final readonly class ScheduleDueRefreshes
{
    public function __construct(
        private ProfileRepository $profiles,
        private RefreshQueue $queue,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $pendingTimeoutSeconds,
    ) {}

    public function execute(int $limit): int
    {
        $now = $this->clock->now();
        $stalePendingBefore = $now->modify(sprintf('-%d seconds', $this->pendingTimeoutSeconds));

        $ids = $this->profiles->claimDue($now, $stalePendingBefore, $limit);
        foreach ($ids as $id) {
            $this->queue->enqueue(new RefreshCommand($id, cycleStartedAt: $now->getTimestamp()));
        }

        $this->logger->info('refresh.scheduled', ['count' => count($ids), 'limit' => $limit]);

        return count($ids);
    }
}
