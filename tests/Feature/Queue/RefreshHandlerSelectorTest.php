<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshesProfile;
use App\Infrastructure\Queue\RefreshHandlerSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\UpstreamFixtures;
use Tests\TestCase;

/**
 * The runtime flag is the rollout/rollback switch: jobs pick the handler at execution time.
 */
final class RefreshHandlerSelectorTest extends TestCase
{
    use RefreshDatabase;
    use UpstreamFixtures;

    public function test_jobs_use_the_fix_by_default(): void
    {
        $id = $this->seedProfile();
        $this->upstreamResponds(200, $this->fixture('new-format.json'));

        $this->app->make(RefreshesProfile::class)->execute(new RefreshCommand($id));

        $this->assertSame(['likes' => 121_000, 'revision' => 11], $this->storedProfile($id));
    }

    public function test_flipping_the_flag_switches_running_workers_to_the_legacy_handler(): void
    {
        Cache::forever(RefreshHandlerSelector::FLAG, RefreshHandlerSelector::LEGACY);
        $id = $this->seedProfile();
        $this->upstreamResponds(200, $this->fixture('new-format.json'));

        $this->app->make(RefreshesProfile::class)->execute(new RefreshCommand($id));

        $this->assertSame(['likes' => 0, 'revision' => 11], $this->storedProfile($id), 'legacy behaviour, as recorded in LegacyBrokenHandlerTest');
    }
}
