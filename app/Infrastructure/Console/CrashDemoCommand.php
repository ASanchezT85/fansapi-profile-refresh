<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Port\RefreshQueue;
use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\TrackProfile;
use App\Infrastructure\FakeUpstream\FakeUpstream;
use App\Infrastructure\Queue\RefreshHandlerSelector;
use App\Infrastructure\Queue\RefreshProfileJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Real crash, real Horizon worker, real Redis: the worker is SIGKILLed right after the database
 * write, before acknowledging the job. Redis keeps the job in the reserved set, migrates it back
 * after retry_after, and another worker runs it again (delivery 2). The upstream serves the same
 * revision again, so the replay must be recorded as "superseded", never as a second success.
 */
final class CrashDemoCommand extends Command
{
    protected $signature = 'refresh:crash-demo {--output= : also write the report to this file}';

    protected $description = 'Kill a worker after its DB write and watch Redis redeliver the job (local only)';

    private const string ACCOUNT = 'acct-calm';

    private const string HANDLE = 'crash-demo-creator';

    private const int POLL_SECONDS = 5;

    private const int GRACE_SECONDS = 60;

    /** @var list<string> */
    private array $report = [];

    public function handle(TrackProfile $track, RefreshQueue $queue, Cache $cache): int
    {
        if (! app()->environment('local') || ! config()->boolean('refresh.chaos.enabled')) {
            $this->error('Needs APP_ENV=local and REFRESH_CHAOS_ENABLED=true (in the workers too).');

            return self::FAILURE;
        }

        DB::statement('TRUNCATE refresh_attempts, profiles RESTART IDENTITY CASCADE');
        Queue::connection('redis')->clear(config()->string('refresh.queue.name'));
        $cache->forever(RefreshHandlerSelector::FLAG, RefreshHandlerSelector::FIXED);
        (new FakeUpstream(Redis::connection()))->prepare(self::ACCOUNT, [self::HANDLE], ['freeze_revision' => true]);

        $id = $track->execute(self::ACCOUNT, self::HANDLE);
        DB::table('profiles')->where('id', $id)->update([
            'likes' => FakeUpstream::expectedLikes(self::HANDLE, FakeUpstream::INITIAL_REVISION),
            'revision' => FakeUpstream::INITIAL_REVISION,
        ]);

        $retryAfter = config()->integer('queue.connections.redis.retry_after');
        $cache->put(RefreshProfileJob::CRASH_AFTER_WRITE, $id, $retryAfter * 3);
        $startedAt = CarbonImmutable::now();
        $queue->enqueue(new RefreshCommand($id, cycleStartedAt: $startedAt->getTimestamp()));
        $this->say(sprintf('Queued profile #%d at %s UTC; the worker will be killed after its DB write. Redis retry_after = %ds.', $id, $startedAt->format('H:i:s'), $retryAfter));

        $deadline = $startedAt->addSeconds($retryAfter + self::GRACE_SECONDS);
        do {
            sleep(self::POLL_SECONDS);
            $deliveries = DB::table('refresh_attempts')->where('profile_id', $id)->max('delivery');
            $this->snapshot($id, $startedAt);
        } while ((is_numeric($deliveries) ? (int) $deliveries : 0) < 2 && CarbonImmutable::now() < $deadline);

        $this->say('');
        $this->say('Attempts recorded (one row per execution):');
        foreach (DB::table('refresh_attempts')->where('profile_id', $id)->orderBy('id')->get(['delivery', 'attempt', 'outcome', 'revision', 'job_id', 'created_at']) as $row) {
            $this->say('  '.json_encode($row));
        }
        $this->say('Profiles with this handle: '.DB::table('profiles')->where('handle', self::HANDLE)->count());
        $this->writeReport();

        return self::SUCCESS;
    }

    private function snapshot(int $id, CarbonImmutable $startedAt): void
    {
        /** @var RedisQueue $redisQueue */
        $redisQueue = Queue::connection('redis');
        $name = config()->string('refresh.queue.name');
        /** @var object{likes: int|null, revision: int|null, last_successful_refresh_at: string|null} $row */
        $row = DB::table('profiles')->find($id, ['likes', 'revision', 'last_successful_refresh_at']);

        $this->say(sprintf(
            't=%3ds  queue ready=%d reserved=%d  profile likes=%s revision=%s last_success=%s  executions=%d',
            (int) round($startedAt->diffInSeconds(CarbonImmutable::now())),
            $redisQueue->pendingJobs($name)->count(),
            $redisQueue->reservedJobs($name)->count(),
            var_export($row->likes, true),
            var_export($row->revision, true),
            $row->last_successful_refresh_at ?? '-',
            DB::table('refresh_attempts')->where('profile_id', $id)->count(),
        ));
    }

    private function say(string $line): void
    {
        $this->report[] = $line;
        $this->line($line);
    }

    private function writeReport(): void
    {
        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            file_put_contents(base_path($output), implode(PHP_EOL, $this->report).PHP_EOL);
            $this->info('Report written to '.$output);
        }
    }
}
