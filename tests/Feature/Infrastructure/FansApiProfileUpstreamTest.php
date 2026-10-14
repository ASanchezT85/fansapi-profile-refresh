<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Application\Port\UpstreamFailure;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\FailureReason;
use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\ProfileHandle;
use App\Domain\Profile\ProfileSnapshot;
use App\Infrastructure\Http\FansApiProfileUpstream;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The real provider's response shape, from its documentation example (tests/Fixtures/upstream/
 * fansapi-docs-example.json) and from any real response recorded with `refresh:live --record`.
 */
final class FansApiProfileUpstreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fansapi.base_url' => 'https://fansapi.test/api', 'services.fansapi.key' => 'test-key']);
    }

    public function test_maps_the_provider_response_into_a_valid_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 12:00:00.123456');
        Http::fake(['*' => Http::response($this->fixture('fansapi-docs-example.json'))]);

        $snapshot = ProfileSnapshot::fromPayload($this->fetch());

        $this->assertSame(1_234, $snapshot->likes->value, 'likes come from data.favoritedCount');
        $this->assertSame(1_791_979_200_123_456, $snapshot->revision->value, 'revision = request start time in microseconds');
        $this->assertSame('madison420ivy', $snapshot->attributes['username']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fansapi.test/api/profiles/madison420ivy'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_a_later_request_always_gets_a_higher_revision(): void
    {
        Http::fake(['*' => Http::response($this->fixture('fansapi-docs-example.json'))]);

        CarbonImmutable::setTestNow('2026-10-14 12:00:00.000001');
        $earlier = ProfileSnapshot::fromPayload($this->fetch());
        CarbonImmutable::setTestNow('2026-10-14 12:00:00.000002');
        $later = ProfileSnapshot::fromPayload($this->fetch());

        $this->assertGreaterThan($earlier->revision->value, $later->revision->value);
    }

    public function test_a_response_without_the_likes_counter_is_invalid(): void
    {
        Http::fake(['*' => Http::response(['data' => ['username' => 'madison420ivy'], '_meta' => []])]);

        $this->expectException(InvalidProfilePayload::class);
        $this->expectExceptionMessage('favoritedCount');
        $this->fetch();
    }

    public function test_a_response_without_data_is_invalid(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'])]);

        $this->expectException(InvalidProfilePayload::class);
        $this->fetch();
    }

    public function test_provider_rate_limits_are_classified_like_any_upstream(): void
    {
        Http::fake(['*' => Http::response('{"message":"Too Many Attempts."}', 429, ['Retry-After' => '30'])]);

        try {
            $this->fetch();
            $this->fail('Expected an UpstreamFailure.');
        } catch (UpstreamFailure $failure) {
            $this->assertSame(FailureReason::RateLimited, $failure->reason);
            $this->assertSame(30, $failure->retryAfterSeconds);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordedResponses(): iterable
    {
        foreach (glob(dirname(__DIR__, 2).'/Fixtures/upstream/fansapi-*.json') ?: [] as $path) {
            yield basename($path) => [basename($path)];
        }
    }

    #[DataProvider('recordedResponses')]
    public function test_every_recorded_provider_response_maps_to_a_valid_snapshot(string $fixture): void
    {
        Http::fake(['*' => Http::response($this->fixture($fixture))]);

        $snapshot = ProfileSnapshot::fromPayload($this->fetch());

        $this->assertGreaterThanOrEqual(0, $snapshot->likes->value);
    }

    public function test_the_live_command_refuses_to_run_without_a_key(): void
    {
        config(['services.fansapi.key' => '']);

        /** @var PendingCommand $command */
        $command = $this->artisan('refresh:live');
        $command->expectsOutputToContain('FANSAPI_KEY is empty')->assertFailed();
    }

    /**
     * @return array<mixed>
     */
    private function fetch(): array
    {
        return $this->app->make(FansApiProfileUpstream::class)->fetch(new AccountId('fansapi-live'), new ProfileHandle('madison420ivy'));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/upstream/'.$name));
    }
}
