<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Port\ProfileUpstream;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\ProfileHandle;
use Psr\Clock\ClockInterface;

/**
 * Adapter for the real provider: OnlyFans API by FansAPI, `GET /api/profiles/{username}`.
 *
 * Transport (status codes, timeouts, Retry-After, body size) is the same as any HTTP upstream, so it is
 * delegated to HttpProfileUpstream. This class is the anti-corruption layer: it translates the
 * provider's shape into the payload the domain validates, so ProfileSnapshot and every rule after it
 * stay unchanged.
 *
 *  - likes    <- data.favoritedCount (the profile's public likes counter). Missing = invalid, as always.
 *  - revision <- the provider exposes no version, so the request start time in microseconds is used.
 *               A response to an older request can then never overwrite the result of a newer one.
 *               Weaker than a real version: two requests started in the same microsecond, or a provider
 *               cache serving older data to a newer request, are not detected.
 *  - everything else in `data` is kept as profile attributes.
 */
final readonly class FansApiProfileUpstream implements ProfileUpstream
{
    private const string LIKES_FIELD = 'favoritedCount';

    public function __construct(
        private HttpProfileUpstream $http,
        private ClockInterface $clock,
    ) {}

    public function fetch(AccountId $account, ProfileHandle $handle): array
    {
        $requestedAt = $this->clock->now();
        $body = $this->http->fetch($account, $handle);

        $data = $body['data'] ?? null;
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidProfilePayload::missingField('data');
        }
        if (! array_key_exists(self::LIKES_FIELD, $data)) {
            throw InvalidProfilePayload::missingField(self::LIKES_FIELD);
        }

        $profile = $data;
        $profile['likes'] = $data[self::LIKES_FIELD];

        return ['profile' => $profile, 'revision' => (int) $requestedAt->format('Uu')];
    }
}
