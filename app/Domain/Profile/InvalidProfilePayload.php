<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use DomainException;

/**
 * The upstream answered, but with something we must not store.
 * Messages describe the shape of the problem only, never the payload itself.
 */
final class InvalidProfilePayload extends DomainException
{
    public static function notAnObject(): self
    {
        return new self('Response body is not a JSON object.');
    }

    public static function tooLarge(int $bytes): self
    {
        return new self(sprintf('Response body is %d bytes, above the allowed maximum.', $bytes));
    }

    public static function missingField(string $field): self
    {
        return new self(sprintf('Field "%s" is missing.', $field));
    }

    public static function invalidField(string $field, mixed $value): self
    {
        return new self(sprintf('Field "%s" must be a non-negative integer, got %s.', $field, get_debug_type($value)));
    }

    public static function negative(string $field): self
    {
        return new self(sprintf('Field "%s" must not be negative.', $field));
    }
}
