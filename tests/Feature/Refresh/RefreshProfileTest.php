<?php

declare(strict_types=1);

namespace Tests\Feature\Refresh;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshOutcome;
use App\Application\Refresh\RefreshProfile;
use App\Application\Refresh\RefreshStatus;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\FailureReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\UpstreamFixtures;
use Tests\TestCase;

/**
 * Behaviour of the fixed use case beyond the regression contract: what is recorded,
 * how failures are retried, and how one account's rate limit is isolated from the others.
 *
 * @phpstan-type ProfileRow object{
 *     profile_data: string|null,
 *     last_successful_refresh_at: string|null,
 *     last_attempt_at: string|null,
 *     last_failure_at: string|null,
 *     last_failure_reason: string|null,
 *     last_failure_detail: string|null,
 *     consecutive_failures: int,
 *     next_refresh_at: string|null,
 *     refresh_pending_since: string|null,
 * }
 */
final class RefreshProfileTest extends TestCase
{
    use RefreshDatabase;
    use UpstreamFixtures;

    private const string NOW = '2026-10-14 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(self::NOW);
    }

    public function test_success_records_success_attempt_and_next_refresh_separately(): void
    {
        $id = $this->seedProfile();
        $this->update($id, ['refresh_pending_since' => '2026-10-14 11:59:00', 'last_failure_reason' => 'server_error', 'consecutive_failures' => 2]);
        $this->upstreamResponds(200, $this->fixture('new-format.json'));

        $outcome = $this->refresh($id);

        $this->assertSame(RefreshStatus::Refreshed, $outcome->status);
        $row = $this->row($id);
        $this->assertSame(self::NOW, $this->time($row->last_successful_refresh_at));
        $this->assertSame(self::NOW, $this->time($row->last_attempt_at));
        $this->assertSame('2026-10-15 12:00:00', $this->time($row->next_refresh_at), '121,000 likes > 100,000: refresh again in 24 h');
        $this->assertNull($row->refresh_pending_since);
        $this->assertSame(0, $row->consecutive_failures);
        $this->assertSame('server_error', $row->last_failure_reason, 'failure history is kept, the timestamps tell it is resolved');
        $this->assertEqualsCanonicalizing(['username' => 'madison420ivy', 'display_name' => 'Fixture Creator', 'posts_count' => 812], json_decode((string) $row->profile_data, true));
        $this->assertDatabaseHas('refresh_attempts', ['profile_id' => $id, 'outcome' => 'refreshed', 'http_status' => 200, 'revision' => 11, 'attempt' => 1]);
    }

    public function test_failure_keeps_last_success_and_records_failure_details(): void
    {
        $id = $this->seedProfile();
        $this->update($id, ['last_successful_refresh_at' => '2026-10-13 12:00:00', 'refresh_pending_since' => '2026-10-14 11:59:00']);
        $this->upstreamResponds(500, '');

        $outcome = $this->refresh($id);

        $this->assertSame(RefreshStatus::RetryScheduled, $outcome->status);
        $row = $this->row($id);
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
        $this->assertSame('2026-10-13 12:00:00', $this->time($row->last_successful_refresh_at));
        $this->assertSame(self::NOW, $this->time($row->last_attempt_at));
        $this->assertSame(self::NOW, $this->time($row->last_failure_at));
        $this->assertSame('server_error', $row->last_failure_reason);
        $this->assertSame('HTTP 500 with a 0-byte body.', $row->last_failure_detail);
        $this->assertSame(1, $row->consecutive_failures);
        $this->assertNotNull($row->refresh_pending_since, 'still pending: a retry is queued');
        $this->assertDatabaseHas('refresh_attempts', ['profile_id' => $id, 'outcome' => 'server_error', 'http_status' => 500]);

        $this->assertCount(1, $this->queue->queued);
        $this->assertSame(2, $this->queue->queued[0]['command']->attempt);
        $this->assertSame(1, $this->queue->queued[0]['delay'], 'first 5xx backoff: 1 s');
    }

    public function test_rate_limit_cools_down_the_account_and_defers_its_other_profiles_without_calling_upstream(): void
    {
        $first = $this->seedProfile();
        $second = $this->seedProfile(handle: 'second-creator');
        $this->upstreamResponds(429, $this->fixture('rate-limited.json'));

        $limited = $this->refresh($first);
        $deferred = $this->refresh($second);

        $this->assertSame(RefreshStatus::RetryScheduled, $limited->status);
        $this->assertSame(FailureReason::RateLimited, $limited->reason);
        $this->assertGreaterThanOrEqual(2, $limited->delaySeconds);
        $this->assertLessThanOrEqual(4, $limited->delaySeconds);
        $this->assertSame($limited->delaySeconds, $this->cooldown->remainingSeconds(new AccountId(self::ACCOUNT)));

        $this->assertSame(RefreshStatus::Deferred, $deferred->status);
        Http::assertSentCount(1);
        $this->assertSame(1, $this->queue->queued[1]['command']->attempt, 'deferral does not spend an attempt');
        $this->assertDatabaseMissing('refresh_attempts', ['profile_id' => $second]);
    }

    public function test_a_cooling_down_account_does_not_block_other_accounts(): void
    {
        $busy = $this->seedProfile();
        $other = DB::table('profiles')->insertGetId(['account_id' => 'acct-other', 'handle' => 'calm-creator', 'likes' => 50, 'revision' => 1]);
        $this->cooldown->coolDown(new AccountId(self::ACCOUNT), 30);
        $this->upstreamResponds(200, $this->fixture('new-format.json'));

        $this->assertSame(RefreshStatus::Deferred, $this->refresh($busy)->status);
        $this->assertSame(RefreshStatus::Refreshed, $this->refresh($other)->status);
    }

    public function test_the_account_recovers_once_the_cooldown_expires(): void
    {
        $id = $this->seedProfile();
        $this->cooldown->coolDown(new AccountId(self::ACCOUNT), 5);
        $this->upstreamResponds(200, $this->fixture('new-format.json'));
        $this->assertSame(RefreshStatus::Deferred, $this->refresh($id)->status);

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW)->addSeconds(6));

        $this->assertSame(RefreshStatus::Refreshed, $this->refresh($id)->status);
    }

    public function test_transient_failures_end_the_cycle_after_the_attempt_limit(): void
    {
        $id = $this->seedProfile();
        $this->update($id, ['refresh_pending_since' => '2026-10-14 11:00:00']);
        $this->upstreamTimesOut();

        $outcome = $this->refresh($id, attempt: 5);

        $this->assertSame(RefreshStatus::Failed, $outcome->status);
        $this->assertSame(FailureReason::Timeout, $outcome->reason);
        $this->assertSame([], $this->queue->queued);
        $row = $this->row($id);
        $this->assertNull($row->refresh_pending_since);
        $this->assertSame('2026-10-14 12:15:00', $this->time($row->next_refresh_at), 'retry the profile 15 min later');
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    public function test_permanent_failures_are_not_retried(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(404, '{"error":"not found"}');

        $outcome = $this->refresh($id);

        $this->assertSame(RefreshStatus::Failed, $outcome->status);
        $this->assertSame(FailureReason::NotFound, $outcome->reason);
        $this->assertSame([], $this->queue->queued);
        $this->assertSame('2026-10-15 12:00:00', $this->time($this->row($id)->next_refresh_at));
    }

    public function test_logs_follow_account_profile_job_and_attempt_without_leaking_the_token(): void
    {
        config(['services.upstream.token' => 'super-secret-token']);
        $id = $this->seedProfile();
        $this->upstreamResponds(500, '');

        $this->app->make(RefreshProfile::class)->execute(new RefreshCommand($id, attempt: 2, jobId: '0f8a2c1e-5b7d-4e2a-9c3f-1a2b3c4d5e6f', delivery: 1));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer super-secret-token'));
        $record = $this->logger->withMessage('refresh.retry_scheduled')[0] ?? null;
        $this->assertNotNull($record);
        $this->assertSame(self::ACCOUNT, $record['context']['account']);
        $this->assertSame($id, $record['context']['profile_id']);
        $this->assertSame('0f8a2c1e-5b7d-4e2a-9c3f-1a2b3c4d5e6f', $record['context']['job_id']);
        $this->assertSame(2, $record['context']['attempt']);
        $this->assertSame(3, $record['context']['next_attempt']);
        $this->assertSame('server_error', $record['context']['reason']);
        $this->assertStringNotContainsString('super-secret-token', (string) json_encode($this->logger->records));
    }

    private function refresh(int $profileId, int $attempt = 1): RefreshOutcome
    {
        return $this->app->make(RefreshProfile::class)->execute(new RefreshCommand($profileId, $attempt));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(int $id, array $values): void
    {
        DB::table('profiles')->where('id', $id)->update($values);
    }

    /**
     * @return ProfileRow
     */
    private function row(int $id): object
    {
        /** @var ProfileRow|null $row */
        $row = DB::table('profiles')->find($id);
        $this->assertNotNull($row);

        return $row;
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) ? CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s') : null;
    }
}
