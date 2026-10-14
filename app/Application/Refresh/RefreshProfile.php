<?php

declare(strict_types=1);

namespace App\Application\Refresh;

use App\Application\Port\AccountCooldown;
use App\Application\Port\AttemptRecord;
use App\Application\Port\ProfileRepository;
use App\Application\Port\ProfileUpstream;
use App\Application\Port\RefreshFailure;
use App\Application\Port\RefreshQueue;
use App\Application\Port\UpstreamFailure;
use App\Domain\Profile\FailureReason;
use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\Profile;
use App\Domain\Profile\ProfileSnapshot;
use App\Domain\Profile\RefreshSchedule;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The fix. One attempt to refresh one profile:
 *
 *  1. If the account is cooling down after a 429, defer without calling the upstream.
 *  2. Fetch; classify transport failures (UpstreamFailure) and invalid bodies (InvalidProfilePayload).
 *  3. Validate into a ProfileSnapshot before touching storage.
 *  4. Store with a revision-guarded atomic write: late or duplicate responses cannot overwrite newer data.
 *  5. On failure keep the last valid data, record the failure separately, and either queue a retry
 *     (RetryPolicy) or end the cycle.
 */
final readonly class RefreshProfile implements RefreshesProfile
{
    public function __construct(
        private ProfileRepository $profiles,
        private ProfileUpstream $upstream,
        private RefreshQueue $queue,
        private AccountCooldown $cooldown,
        private RetryPolicy $retryPolicy,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function execute(RefreshCommand $command): RefreshOutcome
    {
        $profile = $this->profiles->find($command->profileId);
        if ($profile === null) {
            $this->logger->warning('refresh.skipped', ['profile_id' => $command->profileId, 'job_id' => $command->jobId, 'cause' => 'profile_missing']);

            return RefreshOutcome::skipped();
        }

        $context = [
            'account' => $profile->accountId->value,
            'profile_id' => $profile->id,
            'handle' => $profile->handle->value,
            'job_id' => $command->jobId,
            'attempt' => $command->attempt,
            'delivery' => $command->delivery,
        ];

        $cooldown = $this->cooldown->remainingSeconds($profile->accountId);
        if ($cooldown > 0) {
            $delay = $cooldown + $this->retryPolicy->deferralJitter();
            $this->queue->enqueue($command->sameAttempt(), $delay);
            $this->logger->info('refresh.deferred', $context + ['cause' => 'account_cooldown', 'delay_s' => $delay]);

            return RefreshOutcome::deferred($delay);
        }

        $startedAt = hrtime(true);
        try {
            $snapshot = ProfileSnapshot::fromPayload($this->upstream->fetch($profile->accountId, $profile->handle));
        } catch (UpstreamFailure $failure) {
            return $this->fail($command, $profile, $context, $failure->reason, $failure->getMessage(), $failure->httpStatus, $failure->retryAfterSeconds, $startedAt);
        } catch (InvalidProfilePayload $invalid) {
            return $this->fail($command, $profile, $context, FailureReason::InvalidPayload, $invalid->getMessage(), null, null, $startedAt);
        }

        $now = $this->clock->now();
        $applied = $this->profiles->applySnapshot($profile->id, $snapshot, $now, RefreshSchedule::nextRefreshAt($snapshot->likes, $now));
        $outcome = $applied
            ? RefreshOutcome::refreshed($snapshot->revision->value)
            : RefreshOutcome::superseded($snapshot->revision->value);

        $durationMs = $this->elapsedMs($startedAt);
        $this->logAttempt($command, $profile, $outcome->status->value, 200, $snapshot->revision->value, $durationMs);
        $this->logger->info('refresh.'.$outcome->status->value, $context + [
            'revision' => $snapshot->revision->value,
            'likes' => $snapshot->likes->value,
            'duration_ms' => $durationMs,
        ]);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(
        RefreshCommand $command,
        Profile $profile,
        array $context,
        FailureReason $reason,
        string $detail,
        ?int $httpStatus,
        ?int $retryAfterSeconds,
        int|float $startedAt,
    ): RefreshOutcome {
        $now = $this->clock->now();
        $delay = $this->retryPolicy->retryDelay($reason, $command->attempt, $retryAfterSeconds);

        if ($reason === FailureReason::RateLimited) {
            // Pause the whole account, not just this job: its other jobs defer instead of hitting the upstream.
            $this->cooldown->coolDown($profile->accountId, $delay ?? $this->retryPolicy->rateLimitDelay($command->attempt, $retryAfterSeconds));
        }

        $cycleFinished = $delay === null;
        $this->profiles->recordFailure($profile->id, new RefreshFailure(
            $reason,
            $detail,
            $now,
            $cycleFinished,
            $cycleFinished ? $this->retryPolicy->nextRefreshAfterGivingUp($reason, $now) : null,
        ));

        $durationMs = $this->elapsedMs($startedAt);
        $this->logAttempt($command, $profile, $reason->value, $httpStatus, null, $durationMs);
        $failureContext = $context + ['reason' => $reason->value, 'http_status' => $httpStatus, 'detail' => $detail, 'duration_ms' => $durationMs];

        if ($delay === null) {
            $this->logger->warning('refresh.failed', $failureContext + ['retryable' => $reason->isTransient()]);

            return RefreshOutcome::failed($reason);
        }

        $this->queue->enqueue($command->nextAttempt(), $delay);
        $this->logger->notice('refresh.retry_scheduled', $failureContext + ['delay_s' => $delay, 'next_attempt' => $command->attempt + 1]);

        return RefreshOutcome::retryScheduled($reason, $delay);
    }

    private function logAttempt(RefreshCommand $command, Profile $profile, string $outcome, ?int $httpStatus, ?int $revision, int $durationMs): void
    {
        $this->profiles->logAttempt(new AttemptRecord(
            $profile->id,
            $profile->accountId->value,
            $command->attempt,
            $command->delivery,
            $command->jobId,
            $outcome,
            $httpStatus,
            $revision,
            $durationMs,
            $this->clock->now(),
        ));
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
