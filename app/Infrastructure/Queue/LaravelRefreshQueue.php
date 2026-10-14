<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Port\RefreshQueue;
use App\Application\Refresh\RefreshCommand;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelRefreshQueue implements RefreshQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function enqueue(RefreshCommand $command, int $delaySeconds = 0): void
    {
        $job = new RefreshProfileJob($command->profileId, $command->attempt, $command->cycleStartedAt);

        $this->bus->dispatch($delaySeconds > 0 ? $job->delay($delaySeconds) : $job);
    }
}
