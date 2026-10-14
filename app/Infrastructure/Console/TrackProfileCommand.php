<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Refresh\TrackProfile;
use Illuminate\Console\Command;

final class TrackProfileCommand extends Command
{
    protected $signature = 'profiles:track {account : Upstream account id, e.g. acct-main} {handle : Profile username, e.g. madison420ivy}';

    protected $description = 'Start tracking a profile (idempotent); it becomes due immediately';

    public function handle(TrackProfile $track): int
    {
        $id = $track->execute($this->stringArgument('account'), $this->stringArgument('handle'));

        $this->info(sprintf('Tracking profile #%d.', $id));

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
