<?php

declare(strict_types=1);

namespace App\Application\Refresh;

use App\Application\Port\ProfileRepository;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\ProfileHandle;

/**
 * Starts tracking a profile. Safe to call repeatedly or concurrently: returns the existing id.
 * The new profile is due immediately (never refreshed).
 */
final readonly class TrackProfile
{
    public function __construct(private ProfileRepository $profiles) {}

    public function execute(string $accountId, string $handle): int
    {
        return $this->profiles->track(new AccountId($accountId), new ProfileHandle($handle));
    }
}
