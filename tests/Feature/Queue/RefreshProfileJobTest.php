<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Refresh\RefreshesProfile;
use App\Infrastructure\Queue\RefreshProfileJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UpstreamFixtures;
use Tests\TestCase;

final class RefreshProfileJobTest extends TestCase
{
    use RefreshDatabase;
    use UpstreamFixtures;

    /**
     * Simulates "the worker wrote to the database, then died before acknowledging the job":
     * the queue delivers the same job again. This proves the handler is idempotent for a replay.
     * It does NOT prove that Redis redelivers after a real crash (see the crash demo in the README).
     */
    public function test_replaying_a_job_after_its_database_write_changes_nothing(): void
    {
        $id = $this->seedProfile();
        $this->upstreamRespondsInOrder('new-format.json', 'new-format.json');
        $job = new RefreshProfileJob($id);

        CarbonImmutable::setTestNow('2026-10-14 12:00:00');
        $job->handle($this->app->make(RefreshesProfile::class));
        $afterFirst = (array) DB::table('profiles')->find($id);

        CarbonImmutable::setTestNow('2026-10-14 12:00:30');
        $job->handle($this->app->make(RefreshesProfile::class));
        $afterReplay = (array) DB::table('profiles')->find($id);

        $this->assertNotSame([], $afterFirst);
        $this->assertNotSame([], $afterReplay);
        $this->assertSame(1, DB::table('profiles')->count());
        $this->assertSame(['likes' => 121_000, 'revision' => 11], $this->storedProfile($id));
        $this->assertSame($afterFirst['last_successful_refresh_at'], $afterReplay['last_successful_refresh_at'], 'the replay is not a second success');
        $this->assertSame($afterFirst['next_refresh_at'], $afterReplay['next_refresh_at']);
        $this->assertSame(['refreshed', 'superseded'], DB::table('refresh_attempts')->orderBy('id')->pluck('outcome')->all());
    }

    public function test_the_payload_carries_ids_only(): void
    {
        $serialized = serialize(new RefreshProfileJob(42, attempt: 3, cycleStartedAt: 1_760_000_000));

        $this->assertLessThan(1024, strlen($serialized));
        $this->assertStringNotContainsString('likes', $serialized);
    }

    /**
     * The ordering that prevents two workers from running the same attempt (README "Timeouts").
     */
    public function test_timeouts_are_ordered_so_redelivery_cannot_overlap_a_running_attempt(): void
    {
        $httpTimeout = config()->float('refresh.upstream.timeout_seconds') + config()->float('refresh.upstream.connect_timeout_seconds');
        $jobTimeout = (new RefreshProfileJob(1))->timeout;
        $lock = config()->integer('refresh.queue.overlap_lock_seconds');
        $retryAfter = config()->integer('queue.connections.redis.retry_after');

        $this->assertLessThan($jobTimeout, $httpTimeout);
        $this->assertLessThan($lock, $jobTimeout);
        $this->assertLessThan($retryAfter, $lock);
    }
}
