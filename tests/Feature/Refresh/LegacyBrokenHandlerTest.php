<?php

declare(strict_types=1);

namespace Tests\Feature\Refresh;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshOutcome;
use App\Infrastructure\Legacy\LegacyBrokenHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UpstreamFixtures;
use Tests\TestCase;

/**
 * Characterisation tests: they record what the legacy handler actually does
 * (these assertions describe the bug; they pass because the bug is real).
 * Starting point for every case: likes 120,000 at revision 10.
 */
final class LegacyBrokenHandlerTest extends TestCase
{
    use RefreshDatabase;
    use UpstreamFixtures;

    public function test_old_format_is_stored_correctly(): void
    {
        $id = $this->seedProfile(likes: 119_000, revision: 9);
        $this->upstreamResponds(200, $this->fixture('old-format.json'));

        $outcome = $this->legacy($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 120_000, 'revision' => 10], $this->storedProfile($id));
    }

    public function test_new_format_erases_likes_to_zero_and_reports_success(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(200, $this->fixture('new-format.json'));

        $outcome = $this->legacy($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 0, 'revision' => 11], $this->storedProfile($id));
    }

    public function test_rate_limit_without_retry_after_erases_profile_and_reports_success(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(429, $this->fixture('rate-limited.json'));

        $outcome = $this->legacy($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 0, 'revision' => null], $this->storedProfile($id));
    }

    public function test_server_error_with_empty_body_erases_profile_and_reports_success(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(500, '');

        $outcome = $this->legacy($id);

        $this->assertTrue($outcome->isSuccessfulRefresh());
        $this->assertSame(['likes' => 0, 'revision' => null], $this->storedProfile($id));
    }

    private function legacy(int $profileId): RefreshOutcome
    {
        return $this->app->make(LegacyBrokenHandler::class)->execute(new RefreshCommand($profileId));
    }
}
