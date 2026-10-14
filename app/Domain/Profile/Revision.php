<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Upstream version of a profile, strictly increasing. Orders responses without trusting timing:
 * the store only accepts a snapshot whose revision is newer than the one it holds.
 */
final readonly class Revision
{
    private function __construct(public int $value) {}

    public static function fromUntrusted(mixed $raw): self
    {
        return new self(NonNegativeInteger::parse('revision', $raw));
    }
}
