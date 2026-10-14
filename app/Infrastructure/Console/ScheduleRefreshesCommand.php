<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Refresh\ScheduleDueRefreshes;
use Illuminate\Console\Command;

final class ScheduleRefreshesCommand extends Command
{
    protected $signature = 'refresh:schedule {--limit= : Max profiles to claim in this run}';

    protected $description = 'Queue a refresh for every due profile that is not already pending';

    public function handle(ScheduleDueRefreshes $scheduler): int
    {
        $limit = $this->option('limit');
        $queued = $scheduler->execute(is_numeric($limit) ? (int) $limit : config()->integer('refresh.scheduling.batch_size'));

        $this->info(sprintf('Queued %d profile refresh(es).', $queued));

        return self::SUCCESS;
    }
}
