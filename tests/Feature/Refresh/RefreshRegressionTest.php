<?php

declare(strict_types=1);

namespace Tests\Feature\Refresh;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshesProfile;
use App\Application\Refresh\RefreshOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UpstreamFixtures;
use Tests\TestCase;

/**
 * The contract any refresh handler must honour. Runs against whatever RefreshesProfile
 * is bound in the container: red with LegacyBrokenHandler, green with the fix.
 */
final class RefreshRegressionTest extends TestCase
{
    use RefreshDatabase;
    use UpstreamFixtures;

    public function test_changed_format_with_likes_inside_profile_is_stored(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(200, $this->fixture('new-format.json'));

        $outcome = $this->refresh($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 121_000, 'revision' => 11], $this->storedProfile($id));
    }

    public function test_old_format_with_top_level_likes_is_still_accepted(): void
    {
        $id = $this->seedProfile(likes: 119_000, revision: 9);
        $this->upstreamResponds(200, $this->fixture('old-format.json'));

        $outcome = $this->refresh($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    public function test_rate_limit_without_retry_after_keeps_last_valid_profile(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(429, $this->fixture('rate-limited.json'));

        $outcome = $this->refresh($id);

        $this->assertFalse($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    public function test_server_error_with_empty_body_keeps_last_valid_profile(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(500, '');

        $outcome = $this->refresh($id);

        $this->assertFalse($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    public function test_timeout_keeps_last_valid_profile(): void
    {
        $id = $this->seedProfile();
        $this->upstreamTimesOut();

        $outcome = $this->refresh($id);

        $this->assertFalse($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'likes missing' => ['missing-likes.json'];
        yield 'likes negative' => ['negative-likes.json'];
        yield 'likes non-numeric string' => ['non-numeric-likes.json'];
        yield 'likes fractional' => ['fractional-likes.json'];
        yield 'likes null' => ['null-likes.json'];
        yield 'revision missing' => ['missing-revision.json'];
        yield 'body is not JSON' => ['not-json.html'];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected_and_keeps_last_valid_profile(string $fixture): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(200, $this->fixture($fixture));

        $outcome = $this->refresh($id);

        $this->assertFalse($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    public function test_explicit_zero_likes_is_valid(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(200, $this->fixture('zero-likes.json'));

        $outcome = $this->refresh($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 0, 'revision' => 12], $this->storedProfile($id));
    }

    public function test_older_revision_arriving_last_does_not_overwrite_newer_data(): void
    {
        $id = $this->seedProfile(likes: null, revision: null);
        // revision 11 is accepted first, then the slower revision 10 response lands.
        $this->upstreamRespondsInOrder('new-format.json', 'old-format.json');

        $first = $this->refresh($id);
        $late = $this->refresh($id);

        $this->assertTrue($first->isSuccessfulRefresh());
        $this->assertFalse($late->isSuccessfulRefresh());
        $this->assertSame(['likes' => 121_000, 'revision' => 11], $this->storedProfile($id));
    }

    public function test_duplicate_execution_applies_once_and_creates_no_duplicate_profile(): void
    {
        $id = $this->seedProfile();
        $this->upstreamRespondsInOrder('new-format.json', 'new-format.json');

        $first = $this->refresh($id);
        $duplicate = $this->refresh($id);

        $this->assertTrue($first->isSuccessfulRefresh());
        $this->assertFalse($duplicate->isSuccessfulRefresh(), 'A replay of an applied revision is not a second refresh.');
        $this->assertSame(1, DB::table('profiles')->where('handle', self::HANDLE)->count());
        $this->assertSame(['likes' => 121_000, 'revision' => 11], $this->storedProfile($id));
    }

    private function refresh(int $profileId): RefreshOutcome
    {
        return $this->app->make(RefreshesProfile::class)->execute(new RefreshCommand($profileId));
    }
}
