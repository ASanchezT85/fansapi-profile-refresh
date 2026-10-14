<?php

declare(strict_types=1);

namespace App\Application\Refresh;

final readonly class RefreshOutcome
{
    private function __construct(public RefreshStatus $status) {}

    public static function refreshed(): self
    {
        return new self(RefreshStatus::Refreshed);
    }

    /** Only a validated, applied snapshot is a successful refresh. A completed job is not. */
    public function isSuccessfulRefresh(): bool
    {
        return $this->status === RefreshStatus::Refreshed;
    }
}
