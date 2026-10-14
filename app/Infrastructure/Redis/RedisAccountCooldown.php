<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Application\Port\AccountCooldown;
use App\Domain\Profile\AccountId;
use Illuminate\Redis\Connections\Connection;

/**
 * Cooldown = a Redis key with a TTL, shared by every worker. Expiry is the "rate limit cleared" signal:
 * no cleanup job, and a lost key only means one extra upstream call.
 */
final readonly class RedisAccountCooldown implements AccountCooldown
{
    private const string PREFIX = 'refresh:cooldown:';

    public function __construct(private Connection $redis) {}

    public function remainingSeconds(AccountId $account): int
    {
        /** @var int|false $milliseconds */
        $milliseconds = $this->redis->command('pttl', [self::PREFIX.$account->value]);

        // -2 = no key, -1 = no TTL (should not happen; treat as no cooldown rather than block forever).
        return is_int($milliseconds) && $milliseconds > 0 ? (int) ceil($milliseconds / 1000) : 0;
    }

    public function coolDown(AccountId $account, int $seconds): void
    {
        if ($seconds <= 0 || $this->remainingSeconds($account) >= $seconds) {
            return;
        }

        // ponytail: check-then-set race between two workers only picks one of two similar TTLs; harmless.
        $this->redis->command('set', [self::PREFIX.$account->value, '1', ['EX' => $seconds]]);
    }
}
