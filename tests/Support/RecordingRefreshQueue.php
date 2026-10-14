<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\RefreshQueue;
use App\Application\Refresh\RefreshCommand;

final class RecordingRefreshQueue implements RefreshQueue
{
    /** @var list<array{command: RefreshCommand, delay: int}> */
    public array $queued = [];

    public function enqueue(RefreshCommand $command, int $delaySeconds = 0): void
    {
        $this->queued[] = ['command' => $command, 'delay' => $delaySeconds];
    }
}
