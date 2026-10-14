<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\AttemptRecord;
use App\Application\Port\ProfileRepository;
use App\Application\Port\RefreshFailure;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\Profile;
use App\Domain\Profile\ProfileHandle;
use App\Domain\Profile\ProfileSnapshot;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Postgres adapter. Concurrency guarantees come from single statements, not from read-then-write:
 *  - track(): INSERT ... ON CONFLICT DO NOTHING on the (account_id, handle) unique index;
 *  - applySnapshot(): UPDATE ... WHERE revision IS NULL OR revision < :new. Postgres re-checks the
 *    WHERE against the latest committed row when two writers race, so an older revision cannot win;
 *  - claimDue(): UPDATE ... WHERE id IN (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING id.
 */
final readonly class DatabaseProfileRepository implements ProfileRepository
{
    private const string TABLE = 'profiles';

    public function __construct(private ConnectionInterface $db) {}

    public function find(int $profileId): ?Profile
    {
        /** @var object{id: int, account_id: string, handle: string}|null $row */
        $row = $this->profiles()->select(['id', 'account_id', 'handle'])->find($profileId);

        return $row === null
            ? null
            : new Profile($row->id, new AccountId($row->account_id), new ProfileHandle($row->handle));
    }

    public function track(AccountId $account, ProfileHandle $handle): int
    {
        $now = $this->timestamp(new DateTimeImmutable);
        $this->profiles()->insertOrIgnore([
            'account_id' => $account->value,
            'handle' => $handle->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var int $id */
        $id = $this->profiles()->where('account_id', $account->value)->where('handle', $handle->value)->value('id');

        return $id;
    }

    public function applySnapshot(int $profileId, ProfileSnapshot $snapshot, DateTimeImmutable $refreshedAt, DateTimeImmutable $nextRefreshAt): bool
    {
        $at = $this->timestamp($refreshedAt);

        $applied = $this->profiles()
            ->where('id', $profileId)
            ->where(fn (Builder $query) => $query->whereNull('revision')->orWhere('revision', '<', $snapshot->revision->value))
            ->update([
                'likes' => $snapshot->likes->value,
                'revision' => $snapshot->revision->value,
                'profile_data' => json_encode($snapshot->attributes, JSON_THROW_ON_ERROR),
                'last_successful_refresh_at' => $at,
                'last_attempt_at' => $at,
                'consecutive_failures' => 0,
                'next_refresh_at' => $this->timestamp($nextRefreshAt),
                'refresh_pending_since' => null,
                'updated_at' => $at,
            ]);

        if ($applied === 1) {
            return true;
        }

        // Equal or newer revision already stored: the data is current, the cycle is over.
        $this->profiles()->where('id', $profileId)->update([
            'last_attempt_at' => $at,
            'refresh_pending_since' => null,
            'updated_at' => $at,
        ]);

        return false;
    }

    public function recordFailure(int $profileId, RefreshFailure $failure): void
    {
        $at = $this->timestamp($failure->failedAt);
        $values = [
            'last_attempt_at' => $at,
            'last_failure_at' => $at,
            'last_failure_reason' => $failure->reason->value,
            'last_failure_detail' => mb_substr($failure->detail, 0, 500),
            'consecutive_failures' => $this->db->raw('consecutive_failures + 1'),
            'updated_at' => $at,
        ];

        if ($failure->cycleFinished) {
            $values['refresh_pending_since'] = null;
            $values['next_refresh_at'] = $failure->nextRefreshAt === null ? null : $this->timestamp($failure->nextRefreshAt);
        }

        $this->profiles()->where('id', $profileId)->update($values);
    }

    public function claimDue(DateTimeImmutable $now, DateTimeImmutable $stalePendingBefore, int $limit): array
    {
        $at = $this->timestamp($now);
        $rows = $this->db->select(<<<'SQL'
            UPDATE profiles SET refresh_pending_since = ?, updated_at = ?
            WHERE id IN (
                SELECT id FROM profiles
                WHERE (next_refresh_at IS NULL OR next_refresh_at <= ?)
                  AND (refresh_pending_since IS NULL OR refresh_pending_since < ?)
                ORDER BY next_refresh_at ASC NULLS FIRST, id
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id
            SQL, [$at, $at, $at, $this->timestamp($stalePendingBefore), $limit], false);

        /** @var list<object{id: int}> $rows */
        $ids = array_map(static fn (object $row): int => $row->id, $rows);
        sort($ids);

        return $ids;
    }

    public function logAttempt(AttemptRecord $attempt): void
    {
        $this->db->table('refresh_attempts')->insert([
            'profile_id' => $attempt->profileId,
            'account_id' => $attempt->accountId,
            'attempt' => $attempt->attempt,
            'delivery' => $attempt->delivery,
            'job_id' => $attempt->jobId,
            'outcome' => $attempt->outcome,
            'http_status' => $attempt->httpStatus,
            'revision' => $attempt->revision,
            'duration_ms' => $attempt->durationMs,
            'created_at' => $this->timestamp($attempt->at),
        ]);
    }

    private function profiles(): Builder
    {
        return $this->db->table(self::TABLE);
    }

    private function timestamp(DateTimeInterface $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }
}
