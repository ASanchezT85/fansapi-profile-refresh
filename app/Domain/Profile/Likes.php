<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Explicit 0 is a valid value; "missing" is not a value at all (see ProfileSnapshot).
 */
final readonly class Likes
{
    private function __construct(public int $value) {}

    public static function fromUntrusted(mixed $raw): self
    {
        return new self(NonNegativeInteger::parse('likes', $raw));
    }
}
