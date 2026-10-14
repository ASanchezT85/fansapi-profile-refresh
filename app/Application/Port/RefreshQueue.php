<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Refresh\RefreshCommand;

/**
 * Driven port: puts a refresh attempt on the queue. Retries are new queued attempts
 * decided by the application (RetryPolicy), not queue-level redeliveries.
 */
interface RefreshQueue
{
    public function enqueue(RefreshCommand $command, int $delaySeconds = 0): void;
}
