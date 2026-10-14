<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\AccountCooldown;
use App\Domain\Profile\AccountId;
use Carbon\CarbonImmutable;

/**
 * Test double driven by Carbon's test clock (Carbon::setTestNow) instead of wall-clock TTLs.
 */
final class InMemoryAccountCooldown implements AccountCooldown
{
    /** @var array<string, CarbonImmutable> */
    private array $until = [];

    public function remainingSeconds(AccountId $account): int
    {
        $until = $this->until[$account->value] ?? null;
        $now = CarbonImmutable::now();

        return $until === null || $until <= $now ? 0 : (int) ceil($now->diffInSeconds($until));
    }

    public function coolDown(AccountId $account, int $seconds): void
    {
        if ($this->remainingSeconds($account) < $seconds) {
            $this->until[$account->value] = CarbonImmutable::now()->addSeconds($seconds);
        }
    }
}
