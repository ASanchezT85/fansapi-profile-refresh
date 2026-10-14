<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Profile\AccountId;
use App\Infrastructure\Redis\RedisAccountCooldown;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Runs against the real Redis container (database 14, flushed per test), not a fake.
 */
final class RedisAccountCooldownTest extends TestCase
{
    private Connection $redis;

    private RedisAccountCooldown $redisCooldown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = Redis::connection();
        $this->redis->command('flushdb');
        $this->redisCooldown = new RedisAccountCooldown($this->redis);
    }

    public function test_no_cooldown_by_default(): void
    {
        $this->assertSame(0, $this->redisCooldown->remainingSeconds(new AccountId('acct-busy')));
    }

    public function test_cooldown_is_per_account_and_never_shortened(): void
    {
        $busy = new AccountId('acct-busy');

        $this->redisCooldown->coolDown($busy, 20);
        $this->redisCooldown->coolDown($busy, 5);

        $this->assertSame(20, $this->redisCooldown->remainingSeconds($busy));
        $this->assertSame(0, $this->redisCooldown->remainingSeconds(new AccountId('acct-calm')));
    }

    public function test_cooldown_ends_when_the_key_expires(): void
    {
        $busy = new AccountId('acct-busy');
        $this->redisCooldown->coolDown($busy, 10);

        $this->redis->command('pexpire', ['refresh:cooldown:acct-busy', 50]);
        usleep(120_000);

        $this->assertSame(0, $this->redisCooldown->remainingSeconds($busy));
    }
}
