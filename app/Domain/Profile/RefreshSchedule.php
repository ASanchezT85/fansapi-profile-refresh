<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use DateInterval;
use DateTimeImmutable;

/**
 * Business rule: profiles above 100,000 likes refresh every 24 h, all others every 72 h.
 * Exactly 100,000 is "not above", so it belongs to the 72 h group.
 */
final class RefreshSchedule
{
    public const int POPULAR_ABOVE_LIKES = 100_000;

    private const string POPULAR_INTERVAL = 'PT24H';

    private const string DEFAULT_INTERVAL = 'PT72H';

    public static function nextRefreshAt(Likes $likes, DateTimeImmutable $refreshedAt): DateTimeImmutable
    {
        $interval = $likes->value > self::POPULAR_ABOVE_LIKES ? self::POPULAR_INTERVAL : self::DEFAULT_INTERVAL;

        return $refreshedAt->add(new DateInterval($interval));
    }
}
