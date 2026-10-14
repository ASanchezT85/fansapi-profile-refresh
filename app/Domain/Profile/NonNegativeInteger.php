<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Strict parsing shared by Likes and Revision.
 *
 * Accepted: JSON integers >= 0, and strings of digits (some producers stringify big integers).
 * Rejected: missing/null, negatives, fractions (1.5), booleans, any other string.
 */
final class NonNegativeInteger
{
    private const string DIGITS = '/^\d{1,18}$/';

    public static function parse(string $field, mixed $raw): int
    {
        if (is_int($raw)) {
            if ($raw < 0) {
                throw InvalidProfilePayload::negative($field);
            }

            return $raw;
        }

        if (is_string($raw) && preg_match(self::DIGITS, $raw) === 1) {
            return (int) $raw;
        }

        throw InvalidProfilePayload::invalidField($field, $raw);
    }
}
