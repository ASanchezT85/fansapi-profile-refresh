<?php

declare(strict_types=1);

namespace App\Infrastructure\FakeUpstream;

use Illuminate\Redis\Connections\Connection;

/**
 * Local stand-in for the real provider, used by the repeatable workload (never by the unit/feature
 * suite, which uses HTTP fakes and tests/Fixtures). Behaviour is scripted per account in Redis so
 * every web worker process shares it:
 *
 *  - storm_until      : until this unix time every request gets 429 without Retry-After;
 *  - quota_per_second : after the storm, more than N requests in the same second get 429;
 *  - error_every      : every Nth request gets HTTP 500 with an empty body;
 *  - latency_ms       : added to every response;
 *  - freeze_revision  : serve the same revision again (to replay a response).
 *
 * It always answers in the NEW format (likes inside "profile"), which is what broke production.
 * Revisions increase per profile, and likes are a pure function of (handle, revision), so the
 * stored data can be verified afterwards: see expectedLikes().
 */
final readonly class FakeUpstream
{
    public const int INITIAL_REVISION = 10;

    private const string PREFIX = 'fake-upstream:';

    private const int BASE_LIKES = 60_000;

    private const int LIKES_SPREAD = 80_000;

    private const int LIKES_PER_REVISION = 10;

    public function __construct(private Connection $redis) {}

    /**
     * Pure function so the workload can check stored data: spans 60k-140k likes, i.e. both the 24 h
     * and the 72 h refresh groups.
     */
    public static function expectedLikes(string $handle, int $revision): int
    {
        return self::BASE_LIKES + (crc32($handle) % self::LIKES_SPREAD) + $revision * self::LIKES_PER_REVISION;
    }

    /**
     * @param  list<string>  $handles
     * @param  array{storm_seconds?: int, quota_per_second?: int, error_every?: int, latency_ms?: int, freeze_revision?: bool}  $scenario
     */
    public function prepare(string $account, array $handles, array $scenario): void
    {
        $keys = [$this->key('scenario', $account), $this->key('requests', $account), $this->key('stats', $account)];
        foreach ($handles as $handle) {
            $keys[] = $this->key('revision', $account, $handle);
        }
        $this->redis->command('del', $keys);

        foreach ($handles as $handle) {
            $this->redis->command('set', [$this->key('revision', $account, $handle), (string) self::INITIAL_REVISION]);
        }

        $this->redis->command('hmset', [$this->key('scenario', $account), [
            'storm_until' => (string) (microtime(true) + ($scenario['storm_seconds'] ?? 0)),
            'quota_per_second' => (string) ($scenario['quota_per_second'] ?? 0),
            'error_every' => (string) ($scenario['error_every'] ?? 0),
            'latency_ms' => (string) ($scenario['latency_ms'] ?? 0),
            'freeze_revision' => ($scenario['freeze_revision'] ?? false) ? '1' : '0',
        ]]);
    }

    /**
     * @return array{int, string, array<string, string>} status, body, headers
     */
    public function respond(string $account, string $handle): array
    {
        /** @var array<string, string> $scenario */
        $scenario = $this->redis->command('hgetall', [$this->key('scenario', $account)]) ?: [];
        $latencyMs = (int) ($scenario['latency_ms'] ?? 0);
        if ($latencyMs > 0) {
            usleep($latencyMs * 1000);
        }

        $requestNumber = $this->int($this->redis->command('incr', [$this->key('requests', $account)]));
        $now = microtime(true);

        if ($now < (float) ($scenario['storm_until'] ?? 0)) {
            return $this->record($account, 429, '{"error":"Too Many Requests"}');
        }

        $quota = (int) ($scenario['quota_per_second'] ?? 0);
        if ($quota > 0) {
            $window = $this->key('window', $account, (string) (int) $now);
            $inWindow = $this->int($this->redis->command('incr', [$window]));
            $this->redis->command('expire', [$window, 2]);
            if ($inWindow > $quota) {
                return $this->record($account, 429, '{"error":"Too Many Requests"}');
            }
        }

        $errorEvery = (int) ($scenario['error_every'] ?? 0);
        if ($errorEvery > 0 && $requestNumber % $errorEvery === 0) {
            return $this->record($account, 500, '');
        }

        $revisionKey = $this->key('revision', $account, $handle);
        $revision = ($scenario['freeze_revision'] ?? '0') === '1'
            ? $this->int($this->redis->command('get', [$revisionKey])) + 1
            : $this->int($this->redis->command('incr', [$revisionKey]));

        $body = json_encode([
            'profile' => [
                'username' => $handle,
                'display_name' => 'Fixture '.$handle,
                'likes' => self::expectedLikes($handle, $revision),
                'posts_count' => crc32($handle) % 1000,
            ],
            'revision' => $revision,
        ], JSON_THROW_ON_ERROR);

        return $this->record($account, 200, $body);
    }

    /**
     * @return array<int, int> HTTP status => count
     */
    public function stats(string $account): array
    {
        /** @var array<string, string> $stats */
        $stats = $this->redis->command('hgetall', [$this->key('stats', $account)]) ?: [];

        $counts = [];
        foreach ($stats as $status => $count) {
            $counts[(int) $status] = $this->int($count);
        }

        return $counts;
    }

    /**
     * @return array{int, string, array<string, string>}
     */
    private function record(string $account, int $status, string $body): array
    {
        $this->redis->command('hincrby', [$this->key('stats', $account), (string) $status, 1]);

        return [$status, $body, ['Content-Type' => 'application/json']];
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function key(string ...$parts): string
    {
        return self::PREFIX.implode(':', $parts);
    }
}
