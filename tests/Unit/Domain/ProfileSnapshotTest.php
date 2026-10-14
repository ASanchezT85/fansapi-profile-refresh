<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\ProfileSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileSnapshotTest extends TestCase
{
    public function test_old_format_reads_top_level_likes(): void
    {
        $snapshot = ProfileSnapshot::fromPayload(['likes' => 120_000, 'revision' => 10]);

        $this->assertSame(120_000, $snapshot->likes->value);
        $this->assertSame(10, $snapshot->revision->value);
        $this->assertSame([], $snapshot->attributes);
    }

    public function test_new_format_reads_likes_inside_profile_and_keeps_other_fields(): void
    {
        $snapshot = ProfileSnapshot::fromPayload([
            'profile' => ['likes' => 121_000, 'username' => 'madison420ivy', 'posts_count' => 812],
            'revision' => 11,
        ]);

        $this->assertSame(121_000, $snapshot->likes->value);
        $this->assertSame(11, $snapshot->revision->value);
        $this->assertSame(['username' => 'madison420ivy', 'posts_count' => 812], $snapshot->attributes);
    }

    public function test_profile_object_is_authoritative_over_a_stale_top_level_likes(): void
    {
        $snapshot = ProfileSnapshot::fromPayload(['likes' => 1, 'profile' => ['likes' => 121_000], 'revision' => 11]);

        $this->assertSame(121_000, $snapshot->likes->value);
    }

    public function test_explicit_zero_and_digit_strings_are_valid(): void
    {
        $this->assertSame(0, ProfileSnapshot::fromPayload(['profile' => ['likes' => 0], 'revision' => 1])->likes->value);
        $this->assertSame(121_000, ProfileSnapshot::fromPayload(['profile' => ['likes' => '121000'], 'revision' => '11'])->likes->value);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'likes missing (old format)' => [['revision' => 10], 'Field "likes" is missing.'];
        yield 'likes missing (new format)' => [['profile' => ['username' => 'x'], 'revision' => 10], 'Field "likes" is missing.'];
        yield 'likes null' => [['profile' => ['likes' => null], 'revision' => 10], 'got null'];
        yield 'likes negative' => [['profile' => ['likes' => -1], 'revision' => 10], 'must not be negative'];
        yield 'likes negative string' => [['profile' => ['likes' => '-1'], 'revision' => 10], 'got string'];
        yield 'likes word' => [['profile' => ['likes' => 'lots'], 'revision' => 10], 'got string'];
        yield 'likes empty string' => [['profile' => ['likes' => ''], 'revision' => 10], 'got string'];
        yield 'likes fractional' => [['profile' => ['likes' => 1.5], 'revision' => 10], 'got float'];
        yield 'likes boolean' => [['profile' => ['likes' => true], 'revision' => 10], 'got bool'];
        yield 'revision missing' => [['profile' => ['likes' => 5]], 'Field "revision" is missing.'];
        yield 'revision negative' => [['profile' => ['likes' => 5], 'revision' => -3], 'must not be negative'];
        yield 'profile is a list' => [['profile' => [1, 2], 'revision' => 10], 'Field "profile"'];
        yield 'profile is a scalar' => [['profile' => 'x', 'revision' => 10], 'Field "profile"'];
    }

    /**
     * @param  array<mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_are_rejected(array $payload, string $expectedMessage): void
    {
        $this->expectException(InvalidProfilePayload::class);
        $this->expectExceptionMessage($expectedMessage);

        ProfileSnapshot::fromPayload($payload);
    }
}
