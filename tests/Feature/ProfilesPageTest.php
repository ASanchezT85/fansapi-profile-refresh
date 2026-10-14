<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProfilesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_profiles_with_their_refresh_state(): void
    {
        DB::table('profiles')->insert([
            'account_id' => 'acct-main',
            'handle' => 'madison420ivy',
            'likes' => 121_000,
            'revision' => 11,
            'last_failure_reason' => 'rate_limited',
            'last_failure_at' => '2026-10-14 12:00:00',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('madison420ivy')
            ->assertSee('121,000')
            ->assertSee('rate_limited');
    }

    public function test_searches_profiles_through_scout(): void
    {
        DB::table('profiles')->insert([
            ['account_id' => 'acct-main', 'handle' => 'madison420ivy'],
            ['account_id' => 'acct-main', 'handle' => 'someone-else'],
        ]);

        $this->get('/?q=madison')
            ->assertOk()
            ->assertSee('madison420ivy')
            ->assertDontSee('someone-else');
    }
}
