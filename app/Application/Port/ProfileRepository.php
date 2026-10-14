<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Profile\AccountId;
use App\Domain\Profile\Profile;
use App\Domain\Profile\ProfileHandle;
use App\Domain\Profile\ProfileSnapshot;
use DateTimeImmutable;

/**
 * Driven port: profile storage. Implementations must make applySnapshot() atomic.
 */
interface ProfileRepository
{
    public function find(int $profileId): ?Profile;

    /**
     * Idempotent under concurrency: tracking the same account+handle twice returns the same id
     * and never creates a second row (enforced by a unique index, not by a read-then-write).
     */
    public function track(AccountId $account, ProfileHandle $handle): int;

    /**
     * Stores the snapshot only if its revision is newer than the stored one, in a single
     * conditional write. Either way the attempt time is recorded and the refresh stops being pending.
     *
     * @return bool true when applied; false when the stored revision was equal or newer.
     */
    public function applySnapshot(int $profileId, ProfileSnapshot $snapshot, DateTimeImmutable $refreshedAt, DateTimeImmutable $nextRefreshAt): bool;

    /**
     * Records a failed attempt. Never touches likes, revision or last_successful_refresh_at.
     */
    public function recordFailure(int $profileId, RefreshFailure $failure): void;

    /**
     * Atomically marks up to $limit due profiles as pending and returns their ids.
     * Due = next_refresh_at reached (or never refreshed) and not already pending,
     * unless the pending mark is older than $stalePendingBefore (its job was lost).
     *
     * @return list<int>
     */
    public function claimDue(DateTimeImmutable $now, DateTimeImmutable $stalePendingBefore, int $limit): array;

    /** Append-only audit of upstream attempts (feeds the before/after metrics). */
    public function logAttempt(AttemptRecord $attempt): void;
}
