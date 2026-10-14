<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Identity of a tracked profile: what a refresh needs to call the upstream.
 * Stored likes/revision are not loaded on purpose: the revision comparison happens
 * atomically in the store, never on a value read earlier (that would be a race).
 */
final readonly class Profile
{
    public function __construct(
        public int $id,
        public AccountId $accountId,
        public ProfileHandle $handle,
    ) {}
}
