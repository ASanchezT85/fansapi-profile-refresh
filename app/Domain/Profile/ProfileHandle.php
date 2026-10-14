<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Public username of a creator profile, e.g. "madison420ivy". Also used in the upstream URL,
 * so the allowed characters are deliberately narrow.
 */
final readonly class ProfileHandle
{
    private const string PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidIdentifier(sprintf('Invalid profile handle "%s".', $value));
        }
    }
}
