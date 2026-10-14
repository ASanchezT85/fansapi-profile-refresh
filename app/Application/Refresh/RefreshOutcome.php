<?php

declare(strict_types=1);

namespace App\Application\Refresh;

use App\Domain\Profile\FailureReason;

final readonly class RefreshOutcome
{
    private function __construct(
        public RefreshStatus $status,
        public ?FailureReason $reason = null,
        public ?int $delaySeconds = null,
        public ?int $revision = null,
    ) {}

    public static function refreshed(?int $revision = null): self
    {
        return new self(RefreshStatus::Refreshed, revision: $revision);
    }

    public static function superseded(int $revision): self
    {
        return new self(RefreshStatus::Superseded, revision: $revision);
    }

    public static function retryScheduled(FailureReason $reason, int $delaySeconds): self
    {
        return new self(RefreshStatus::RetryScheduled, $reason, $delaySeconds);
    }

    public static function deferred(int $delaySeconds): self
    {
        return new self(RefreshStatus::Deferred, delaySeconds: $delaySeconds);
    }

    public static function failed(FailureReason $reason): self
    {
        return new self(RefreshStatus::Failed, $reason);
    }

    public static function skipped(): self
    {
        return new self(RefreshStatus::Skipped);
    }

    /** Only a validated, applied snapshot is a successful refresh. A completed job is not. */
    public function isSuccessfulRefresh(): bool
    {
        return $this->status === RefreshStatus::Refreshed;
    }
}
