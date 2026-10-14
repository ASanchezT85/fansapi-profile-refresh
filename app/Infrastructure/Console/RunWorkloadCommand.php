<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Refresh\ScheduleDueRefreshes;
use App\Application\Refresh\TrackProfile;
use App\Infrastructure\FakeUpstream\FakeUpstream;
use App\Infrastructure\Queue\RefreshHandlerSelector;
use App\Infrastructure\Queue\RefreshProfileJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Repeatable two-account workload against the running Horizon workers and the local fake upstream.
 *
 *   acct-busy: N profiles; the upstream answers 429 (no Retry-After) to everything for --storm
 *              seconds, then allows --busy-quota requests per second.
 *   acct-calm: M profiles; the upstream answers HTTP 500 (empty body) to every Nth request.
 *
 * Every profile starts with valid data at revision 10 and is due. The upstream answers in the new
 * format. Run it with --handler=legacy (before) and --handler=fixed (after): same load, same seed.
 *
 * Measures, per account: reported vs verified successful refreshes, upstream requests per verified
 * refresh, age of the oldest waiting job (from the cycle start, kept across retries), and checks
 * every stored row against the value the upstream served.
 */
final class RunWorkloadCommand extends Command
{
    protected $signature = 'refresh:workload
        {--handler=fixed : legacy|fixed}
        {--busy=30 : profiles on the account that gets rate limited}
        {--calm=10 : profiles on the healthy account}
        {--storm=8 : seconds during which the busy account only gets 429}
        {--busy-quota=5 : requests/second the busy account may make after the storm}
        {--calm-error-every=6 : every Nth calm request gets HTTP 500 with an empty body}
        {--latency=40 : upstream latency in milliseconds}
        {--max-seconds=120 : stop waiting after this many seconds}
        {--output= : also write the report to this file}';

    protected $description = 'Run the repeatable two-account refresh workload (local only; wipes profiles)';

    private const string BUSY = 'acct-busy';

    private const string CALM = 'acct-calm';

    private const int SAMPLE_EVERY_SECONDS = 2;

    /** @var list<string> */
    private array $report = [];

    public function handle(ScheduleDueRefreshes $scheduler, TrackProfile $track, Cache $cache): int
    {
        if (! app()->environment('local')) {
            $this->error('The workload wipes the profiles table: it only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $handler = $this->option('handler');
        if (! in_array($handler, [RefreshHandlerSelector::LEGACY, RefreshHandlerSelector::FIXED], true)) {
            $this->error('--handler must be legacy or fixed.');

            return self::FAILURE;
        }

        $accounts = [
            self::BUSY => $this->handles('busy', $this->intOption('busy')),
            self::CALM => $this->handles('calm', $this->intOption('calm')),
        ];
        $settings = [
            'handler' => $handler,
            'busy_profiles' => count($accounts[self::BUSY]),
            'calm_profiles' => count($accounts[self::CALM]),
            'busy_storm_seconds' => $this->intOption('storm'),
            'busy_quota_per_second' => $this->intOption('busy-quota'),
            'calm_500_every_nth_request' => $this->intOption('calm-error-every'),
            'upstream_latency_ms' => $this->intOption('latency'),
            'horizon_workers' => config()->integer('horizon.environments.local.refresh-supervisor.maxProcesses'),
            'max_attempts' => config()->integer('refresh.retry.max_attempts'),
        ];

        $this->reset($accounts);
        $profileAccount = $this->seed($track, $accounts);
        $upstream = new FakeUpstream(Redis::connection());
        $upstream->prepare(self::BUSY, $accounts[self::BUSY], [
            'storm_seconds' => $settings['busy_storm_seconds'],
            'quota_per_second' => $settings['busy_quota_per_second'],
            'latency_ms' => $settings['upstream_latency_ms'],
        ]);
        $upstream->prepare(self::CALM, $accounts[self::CALM], [
            'error_every' => $settings['calm_500_every_nth_request'],
            'latency_ms' => $settings['upstream_latency_ms'],
        ]);
        $cache->forever(RefreshHandlerSelector::FLAG, $handler);

        $this->say('Workload settings: '.json_encode($settings));
        $startedAt = CarbonImmutable::now();
        $queued = $scheduler->execute(limit: 100_000);
        $this->say(sprintf('Scheduled %d refreshes at %s UTC. Timeline (every %ds):', $queued, $startedAt->format('H:i:s'), self::SAMPLE_EVERY_SECONDS));

        $maxAge = [self::BUSY => 0, self::CALM => 0];
        $allVerifiedAt = [self::BUSY => null, self::CALM => null];
        $deadline = $startedAt->addSeconds($this->intOption('max-seconds'));

        do {
            sleep(self::SAMPLE_EVERY_SECONDS);
            $elapsed = (int) round($startedAt->diffInSeconds(CarbonImmutable::now()));
            $queue = $this->queueSnapshot($profileAccount);
            $data = $this->dataSnapshot($startedAt);

            $parts = [];
            foreach ([self::BUSY, self::CALM] as $account) {
                $maxAge[$account] = max($maxAge[$account], $queue['accounts'][$account]['oldest_age']);
                if ($allVerifiedAt[$account] === null && $data[$account]['verified'] === count($accounts[$account])) {
                    $allVerifiedAt[$account] = $elapsed;
                }
                $stats = $upstream->stats($account);
                $parts[] = sprintf(
                    '%s ok %2d/%d (verified %2d) waiting %2d oldest %3ds | upstream 200:%d 429:%d 500:%d',
                    $account,
                    $data[$account]['reported'],
                    count($accounts[$account]),
                    $data[$account]['verified'],
                    $queue['accounts'][$account]['waiting'],
                    $queue['accounts'][$account]['oldest_age'],
                    $stats[200] ?? 0,
                    $stats[429] ?? 0,
                    $stats[500] ?? 0,
                );
            }
            $this->say(sprintf('t=%3ds  %s  reserved %d', $elapsed, implode('  ||  ', $parts), $queue['reserved']));
        } while ($queue['total'] > 0 && CarbonImmutable::now() < $deadline);

        $this->summary($accounts, $upstream, $startedAt, $maxAge, $allVerifiedAt, $queue['total']);
        $this->writeReport();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<string>>  $accounts
     */
    private function reset(array $accounts): void
    {
        DB::statement('TRUNCATE refresh_attempts, profiles RESTART IDENTITY CASCADE');
        Queue::connection('redis')->clear(config()->string('refresh.queue.name'));
        Redis::connection()->command('del', array_map(fn (string $account): string => 'refresh:cooldown:'.$account, array_keys($accounts)));
    }

    /**
     * Every profile starts with the last valid data at revision 10 and is due now.
     *
     * @param  array<string, list<string>>  $accounts
     * @return array<int, string> profile id => account
     */
    private function seed(TrackProfile $track, array $accounts): array
    {
        $profileAccount = [];
        foreach ($accounts as $account => $handles) {
            foreach ($handles as $handle) {
                $id = $track->execute($account, $handle);
                DB::table('profiles')->where('id', $id)->update([
                    'likes' => FakeUpstream::expectedLikes($handle, FakeUpstream::INITIAL_REVISION),
                    'revision' => FakeUpstream::INITIAL_REVISION,
                    'last_successful_refresh_at' => CarbonImmutable::now()->subDays(3),
                ]);
                $profileAccount[$id] = $account;
            }
        }

        return $profileAccount;
    }

    /**
     * Waiting = ready + delayed jobs. Age is measured from the refresh cycle start, which retries keep,
     * so a job that keeps failing keeps getting older (the incident's symptom) instead of looking new.
     *
     * @param  array<int, string>  $profileAccount
     * @return array{accounts: array<string, array{waiting: int, oldest_age: int}>, reserved: int, total: int}
     */
    private function queueSnapshot(array $profileAccount): array
    {
        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $name = config()->string('refresh.queue.name');
        $now = CarbonImmutable::now()->getTimestamp();
        $waiting = [self::BUSY => 0, self::CALM => 0];
        $oldestAge = [self::BUSY => 0, self::CALM => 0];

        foreach ($queue->pendingJobs($name)->merge($queue->delayedJobs($name)) as $inspected) {
            $job = $this->unwrap($inspected);
            $account = $job === null ? null : ($profileAccount[$job->profileId] ?? null);
            if ($job === null || $account === null) {
                continue;
            }
            $waiting[$account]++;
            $oldestAge[$account] = max($oldestAge[$account], $now - ($job->cycleStartedAt ?? $now));
        }

        $reserved = $queue->reservedJobs($name)->count();
        $accounts = [];
        foreach ($waiting as $account => $count) {
            $accounts[$account] = ['waiting' => $count, 'oldest_age' => $oldestAge[$account]];
        }

        return ['accounts' => $accounts, 'reserved' => $reserved, 'total' => array_sum($waiting) + $reserved];
    }

    private function unwrap(InspectedJob $inspected): ?RefreshProfileJob
    {
        $command = $inspected->payload['data']['command'] ?? null;
        $job = is_string($command) ? unserialize($command, ['allowed_classes' => [RefreshProfileJob::class]]) : null;

        return $job instanceof RefreshProfileJob ? $job : null;
    }

    /**
     * reported = the handler claimed success (last_successful_refresh_at moved);
     * verified = stored likes equal what the upstream served for the stored revision, and that revision is new.
     *
     * @return array<string, array{reported: int, verified: int, corrupted: int, untouched: int}>
     */
    private function dataSnapshot(CarbonImmutable $startedAt): array
    {
        $result = [];
        foreach ([self::BUSY, self::CALM] as $account) {
            $result[$account] = ['reported' => 0, 'verified' => 0, 'corrupted' => 0, 'untouched' => 0];
        }

        /** @var object{account_id: string, handle: string, likes: int|null, revision: int|null, last_successful_refresh_at: string|null} $row */
        foreach (DB::table('profiles')->get(['account_id', 'handle', 'likes', 'revision', 'last_successful_refresh_at']) as $row) {
            $counts = &$result[$row->account_id];
            if ($row->last_successful_refresh_at !== null && CarbonImmutable::parse($row->last_successful_refresh_at) >= $startedAt) {
                $counts['reported']++;
            }

            $valid = $row->revision !== null && $row->likes === FakeUpstream::expectedLikes($row->handle, $row->revision);
            if (! $valid) {
                $counts['corrupted']++;
            } elseif ($row->revision > FakeUpstream::INITIAL_REVISION) {
                $counts['verified']++;
            } else {
                $counts['untouched']++;
            }
            unset($counts);
        }

        return $result;
    }

    /**
     * @param  array<string, list<string>>  $accounts
     * @param  array<string, int>  $maxAge
     * @param  array<string, int|null>  $allVerifiedAt
     */
    private function summary(array $accounts, FakeUpstream $upstream, CarbonImmutable $startedAt, array $maxAge, array $allVerifiedAt, int $leftInQueue): void
    {
        $data = $this->dataSnapshot($startedAt);
        $rows = [];
        foreach ($accounts as $account => $handles) {
            $stats = $upstream->stats($account);
            $requests = array_sum($stats);
            $verified = $data[$account]['verified'];
            $rows[] = [
                $account,
                count($handles),
                $data[$account]['reported'],
                $verified,
                $data[$account]['corrupted'],
                $data[$account]['untouched'],
                sprintf('%d (429: %d, 500: %d)', $requests, $stats[429] ?? 0, $stats[500] ?? 0),
                $verified > 0 ? number_format($requests / $verified, 2) : 'n/a (0 verified)',
                $maxAge[$account].'s',
                $allVerifiedAt[$account] === null ? 'never' : $allVerifiedAt[$account].'s',
            ];
        }

        $headers = ['account', 'profiles', 'reported ok', 'verified ok', 'corrupted', 'not refreshed', 'upstream requests', 'requests / verified ok', 'oldest waiting job (max)', 'all verified at'];
        $this->say('');
        $this->say('Summary:');
        foreach ($this->renderTable($headers, $rows) as $line) {
            $this->say($line);
        }
        $this->say(sprintf('Jobs left in queue: %d.', $leftInQueue));

        $this->say('Stored data sample (first profile of each account):');
        foreach ($accounts as $handles) {
            /** @var object{account_id: string, handle: string, likes: int|null, revision: int|null, last_failure_reason: string|null} $row */
            $row = DB::table('profiles')->where('handle', $handles[0])->first(['account_id', 'handle', 'likes', 'revision', 'last_failure_reason']);
            $expected = $row->revision === null ? 'n/a' : (string) FakeUpstream::expectedLikes($row->handle, $row->revision);
            $this->say(sprintf('  %s/%s likes=%s revision=%s expected_likes=%s last_failure=%s', $row->account_id, $row->handle, var_export($row->likes, true), var_export($row->revision, true), $expected, $row->last_failure_reason ?? '-'));
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<int|string>>  $rows
     * @return list<string>
     */
    private function renderTable(array $headers, array $rows): array
    {
        $widths = array_map(fn (int $i): int => max(array_map(fn (array $r): int => mb_strlen((string) $r[$i]), [...$rows, $headers])), array_keys($headers));
        $line = fn (array $cells): string => '| '.implode(' | ', array_map(fn ($cell, int $w): string => str_pad((string) $cell, $w), $cells, $widths)).' |';

        return [$line($headers), '|'.implode('|', array_map(fn (int $w): string => str_repeat('-', $w + 2), $widths)).'|', ...array_map($line, $rows)];
    }

    /**
     * @return list<string>
     */
    private function handles(string $prefix, int $count): array
    {
        return array_map(fn (int $i): string => sprintf('%s-creator-%02d', $prefix, $i), range(1, max(1, $count)));
    }

    private function intOption(string $name): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : 0;
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
