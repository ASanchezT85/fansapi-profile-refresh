<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * The upstream account (credential) a profile is fetched through. Rate limits apply per account.
 */
final readonly class AccountId
{
    private const string PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidIdentifier(sprintf('Invalid account id "%s".', $value));
        }
    }
}
