<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Profile\AccountId;

/**
 * Driven port: shared, per-account pause after the upstream rate-limits that account.
 * While an account cools down its jobs are deferred without calling the upstream, so a
 * rate-limited account neither hammers the upstream nor holds workers other accounts need.
 */
interface AccountCooldown
{
    public function remainingSeconds(AccountId $account): int;

    /** Starts (or extends, never shortens) the cooldown. */
    public function coolDown(AccountId $account, int $seconds): void;
}
