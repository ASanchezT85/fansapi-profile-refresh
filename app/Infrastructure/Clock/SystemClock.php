<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Backed by Carbon so tests can freeze time with Carbon::setTestNow().
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
