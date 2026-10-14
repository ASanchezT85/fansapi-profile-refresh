<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Refresh\RetryPolicy;
use App\Application\Refresh\RetrySettings;
use App\Domain\Profile\FailureReason;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RetryPolicyTest extends TestCase
{
    private function policy(int $seed = 42): RetryPolicy
    {
        return new RetryPolicy(
            new RetrySettings(
                maxAttempts: 5,
                rateLimitBaseSeconds: 2,
                backoffBaseSeconds: 1,
                maxDelaySeconds: 60,
                deferralJitterSeconds: 2,
                exhaustedRetryDelaySeconds: 900,
                permanentFailureDelaySeconds: 86_400,
            ),
            new Randomizer(new Mt19937($seed)),
        );
    }

    /**
     * @return iterable<string, array{FailureReason}>
     */
    public static function permanentReasons(): iterable
    {
        yield 'invalid payload' => [FailureReason::InvalidPayload];
        yield 'not found' => [FailureReason::NotFound];
        yield 'rejected' => [FailureReason::Rejected];
    }

    #[DataProvider('permanentReasons')]
    public function test_permanent_failures_are_never_retried(FailureReason $reason): void
    {
        $this->assertNull($this->policy()->retryDelay($reason, attempt: 1));
    }

    public function test_transient_failures_stop_at_the_attempt_limit(): void
    {
        $policy = $this->policy();

        $this->assertNotNull($policy->retryDelay(FailureReason::ServerError, attempt: 4));
        $this->assertNull($policy->retryDelay(FailureReason::ServerError, attempt: 5));
        $this->assertNull($policy->retryDelay(FailureReason::RateLimited, attempt: 5));
    }

    public function test_rate_limit_without_retry_after_waits_a_random_delay_that_grows_per_attempt(): void
    {
        $policy = $this->policy();

        foreach ([1 => [2, 4], 2 => [4, 8], 3 => [8, 16], 4 => [16, 32]] as $attempt => [$min, $max]) {
            $delays = array_map(fn (): int => (int) $policy->retryDelay(FailureReason::RateLimited, $attempt), range(1, 50));

            $this->assertGreaterThanOrEqual($min, min($delays), "attempt $attempt");
            $this->assertLessThanOrEqual($max, max($delays), "attempt $attempt");
            $this->assertGreaterThan(1, count(array_unique($delays)), "attempt $attempt delays must be randomized");
        }
    }

    public function test_rate_limit_with_retry_after_honours_it_but_caps_it(): void
    {
        $this->assertSame(7, $this->policy()->retryDelay(FailureReason::RateLimited, 1, retryAfterSeconds: 7));
        $this->assertSame(60, $this->policy()->retryDelay(FailureReason::RateLimited, 1, retryAfterSeconds: 3600));
    }

    public function test_server_errors_and_timeouts_back_off_exponentially_with_jitter(): void
    {
        $policy = $this->policy();

        foreach ([FailureReason::ServerError, FailureReason::Timeout, FailureReason::ConnectionFailed] as $reason) {
            foreach ([1 => [1, 1], 2 => [1, 2], 3 => [2, 4], 4 => [4, 8]] as $attempt => [$min, $max]) {
                $delay = $policy->retryDelay($reason, $attempt);
                $this->assertNotNull($delay);
                $this->assertGreaterThanOrEqual($min, $delay);
                $this->assertLessThanOrEqual($max, $delay);
            }
        }
    }

    public function test_giving_up_schedules_the_next_cycle_later_depending_on_the_reason(): void
    {
        $now = new DateTimeImmutable('2026-10-14 12:00:00+00:00');

        $this->assertSame(900, $this->policy()->nextRefreshAfterGivingUp(FailureReason::RateLimited, $now)->getTimestamp() - $now->getTimestamp());
        $this->assertSame(86_400, $this->policy()->nextRefreshAfterGivingUp(FailureReason::NotFound, $now)->getTimestamp() - $now->getTimestamp());
    }

    public function test_same_seed_gives_the_same_decisions(): void
    {
        $this->assertSame(
            $this->policy(7)->retryDelay(FailureReason::RateLimited, 3),
            $this->policy(7)->retryDelay(FailureReason::RateLimited, 3),
        );
    }
}
