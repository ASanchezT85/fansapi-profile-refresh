<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * A validated upstream profile at one revision. The only way to build one is fromPayload(),
 * so anything that reaches the store has passed validation.
 *
 * Accepted formats:
 *   old: {"likes": 120000, "revision": 10}
 *   new: {"profile": {"likes": 121000, ...}, "revision": 11}
 * When "profile" is present it is authoritative; top-level likes is then ignored.
 */
final readonly class ProfileSnapshot
{
    /**
     * @param  array<string, mixed>  $attributes  Remaining profile fields (name, counters...), stored as-is.
     */
    private function __construct(
        public Likes $likes,
        public Revision $revision,
        public array $attributes,
    ) {}

    /**
     * @param  array<mixed>  $payload  Decoded JSON object.
     *
     * @throws InvalidProfilePayload
     */
    public static function fromPayload(array $payload): self
    {
        $source = $payload;
        if (array_key_exists('profile', $payload)) {
            if (! is_array($payload['profile']) || array_is_list($payload['profile'])) {
                throw InvalidProfilePayload::invalidField('profile', $payload['profile']);
            }
            $source = $payload['profile'];
        }

        if (! array_key_exists('likes', $source)) {
            throw InvalidProfilePayload::missingField('likes');
        }
        if (! array_key_exists('revision', $payload)) {
            throw InvalidProfilePayload::missingField('revision');
        }

        /** @var array<string, mixed> $attributes */
        $attributes = array_diff_key($source, ['likes' => true, 'revision' => true, 'profile' => true]);

        return new self(
            Likes::fromUntrusted($source['likes']),
            Revision::fromUntrusted($payload['revision']),
            $attributes,
        );
    }
}
