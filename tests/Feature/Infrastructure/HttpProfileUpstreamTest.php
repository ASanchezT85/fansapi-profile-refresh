<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Application\Port\ProfileUpstream;
use App\Application\Port\UpstreamFailure;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\FailureReason;
use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\ProfileHandle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HttpProfileUpstreamTest extends TestCase
{
    public function test_requests_the_profile_with_token_and_account_headers(): void
    {
        config(['services.upstream.token' => 'test-token']);
        Http::fake(['*' => Http::response(['profile' => ['likes' => 1], 'revision' => 1])]);

        $payload = $this->fetch();

        $this->assertSame(['profile' => ['likes' => 1], 'revision' => 1], $payload);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://upstream.test/profiles/madison420ivy'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('X-Account-Id', 'acct-main'));
    }

    /**
     * @return iterable<string, array{int, array<string, string>, FailureReason, int|null}>
     */
    public static function failingStatuses(): iterable
    {
        yield '429 without Retry-After' => [429, [], FailureReason::RateLimited, null];
        yield '429 with Retry-After seconds' => [429, ['Retry-After' => '7'], FailureReason::RateLimited, 7];
        yield '500' => [500, [], FailureReason::ServerError, null];
        yield '503' => [503, [], FailureReason::ServerError, null];
        yield '404' => [404, [], FailureReason::NotFound, null];
        yield '410' => [410, [], FailureReason::NotFound, null];
        yield '401' => [401, [], FailureReason::Rejected, null];
        yield '403' => [403, [], FailureReason::Rejected, null];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('failingStatuses')]
    public function test_classifies_http_failures(int $status, array $headers, FailureReason $reason, ?int $retryAfter): void
    {
        Http::fake(['*' => Http::response('', $status, $headers)]);

        try {
            $this->fetch();
            $this->fail('Expected an UpstreamFailure.');
        } catch (UpstreamFailure $failure) {
            $this->assertSame($reason, $failure->reason);
            $this->assertSame($status, $failure->httpStatus);
            $this->assertSame($retryAfter, $failure->retryAfterSeconds);
        }
    }

    public function test_parses_retry_after_given_as_an_http_date(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30)])]);

        try {
            $this->fetch();
            $this->fail('Expected an UpstreamFailure.');
        } catch (UpstreamFailure $failure) {
            $this->assertNotNull($failure->retryAfterSeconds);
            $this->assertEqualsWithDelta(30, $failure->retryAfterSeconds, 2);
        }
    }

    public function test_a_timeout_is_classified_as_timeout(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')]);

        $this->assertFailureReason(FailureReason::Timeout);
    }

    public function test_a_refused_connection_is_not_a_timeout(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect to upstream port 443')]);

        $this->assertFailureReason(FailureReason::ConnectionFailed);
    }

    public function test_rejects_bodies_that_are_not_a_json_object(): void
    {
        Http::fake(['*' => Http::response('[1,2,3]', 200)]);

        $this->expectException(InvalidProfilePayload::class);
        $this->fetch();
    }

    public function test_rejects_bodies_above_the_size_limit(): void
    {
        config(['refresh.upstream.max_body_bytes' => 64]);
        Http::fake(['*' => Http::response(['profile' => ['likes' => 1, 'bio' => str_repeat('x', 100)], 'revision' => 1])]);

        $this->expectException(InvalidProfilePayload::class);
        $this->expectExceptionMessage('above the allowed maximum');
        $this->fetch();
    }

    /**
     * @return array<mixed>
     */
    private function fetch(): array
    {
        return $this->app->make(ProfileUpstream::class)->fetch(new AccountId('acct-main'), new ProfileHandle('madison420ivy'));
    }

    private function assertFailureReason(FailureReason $reason): void
    {
        try {
            $this->fetch();
            $this->fail('Expected an UpstreamFailure.');
        } catch (UpstreamFailure $failure) {
            $this->assertSame($reason, $failure->reason);
        }
    }
}
