<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Application\Port\ProfileRepository;
use App\Application\Refresh\ScheduleDueRefreshes;
use App\Application\Refresh\TrackProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseProfileRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_the_same_profile_twice_creates_one_row(): void
    {
        $track = $this->app->make(TrackProfile::class);

        $first = $track->execute('acct-main', 'madison420ivy');
        $second = $track->execute('acct-main', 'madison420ivy');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('profiles')->count());
    }

    public function test_the_unique_index_refuses_a_duplicate_insert_that_bypasses_the_repository(): void
    {
        DB::table('profiles')->insert(['account_id' => 'acct-main', 'handle' => 'madison420ivy']);

        $this->expectException(QueryException::class);
        DB::table('profiles')->insert(['account_id' => 'acct-main', 'handle' => 'madison420ivy']);
    }

    public function test_the_database_refuses_negative_likes_even_without_the_domain(): void
    {
        $this->expectException(QueryException::class);
        DB::table('profiles')->insert(['account_id' => 'acct-main', 'handle' => 'madison420ivy', 'likes' => -1]);
    }

    public function test_claiming_marks_due_profiles_pending_so_they_are_not_scheduled_twice(): void
    {
        $now = CarbonImmutable::parse('2026-10-14 12:00:00');
        $never = $this->profile('never-refreshed', next: null);
        $due = $this->profile('due', next: '2026-10-14 11:00:00');
        $this->profile('not-due', next: '2026-10-15 11:00:00');
        $this->profile('already-pending', next: null, pending: '2026-10-14 11:55:00');
        $stale = $this->profile('lost-job', next: null, pending: '2026-10-14 11:00:00');
        $repository = $this->app->make(ProfileRepository::class);

        $claimed = $repository->claimDue($now, $now->subMinutes(15), 100);
        $claimedAgain = $repository->claimDue($now, $now->subMinutes(15), 100);

        $this->assertSame([$never, $due, $stale], $claimed);
        $this->assertSame([], $claimedAgain);
    }

    public function test_scheduler_runs_twice_but_queues_each_due_profile_once(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 12:00:00');
        $a = $this->profile('a', next: null);
        $b = $this->profile('b', next: '2026-10-14 11:00:00');
        $scheduler = $this->app->make(ScheduleDueRefreshes::class);

        $this->assertSame(2, $scheduler->execute(limit: 100));
        $this->assertSame(0, $scheduler->execute(limit: 100));

        $this->assertSame([$a, $b], array_map(fn (array $q): int => $q['command']->profileId, $this->queue->queued));
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $this->queue->queued[0]['command']->cycleStartedAt);
    }

    private function profile(string $handle, ?string $next, ?string $pending = null): int
    {
        return DB::table('profiles')->insertGetId([
            'account_id' => 'acct-main',
            'handle' => $handle,
            'next_refresh_at' => $next,
            'refresh_pending_since' => $pending,
        ]);
    }
}
