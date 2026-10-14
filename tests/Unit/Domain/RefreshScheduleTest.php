<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Profile\Likes;
use App\Domain\Profile\RefreshSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function likesToHours(): iterable
    {
        yield 'zero likes' => [0, 72];
        yield 'just below the threshold' => [99_999, 72];
        yield 'exactly 100,000 belongs to the 72 h group' => [100_000, 72];
        yield 'just above the threshold' => [100_001, 24];
        yield 'statement profile (121,000)' => [121_000, 24];
    }

    #[DataProvider('likesToHours')]
    public function test_refresh_interval_depends_on_likes(int $likes, int $expectedHours): void
    {
        $refreshedAt = new DateTimeImmutable('2026-10-14 12:00:00+00:00');

        $next = RefreshSchedule::nextRefreshAt(Likes::fromUntrusted($likes), $refreshedAt);

        $this->assertSame($expectedHours * 3600, $next->getTimestamp() - $refreshedAt->getTimestamp());
    }
}
