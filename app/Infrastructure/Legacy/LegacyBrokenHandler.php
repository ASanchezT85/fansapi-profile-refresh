<?php

declare(strict_types=1);

namespace App\Infrastructure\Legacy;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshesProfile;
use App\Application\Refresh\RefreshOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The handler running in production when the incident started. Kept verbatim in behaviour
 * so the bug can be reproduced and measured. Do not fix it: use RefreshProfile instead.
 *
 * Bugs, all intentional here:
 *  - reads `likes` from the top level only (the upstream moved it to `profile.likes`);
 *  - a missing value silently becomes 0;
 *  - never looks at the HTTP status, so 429/500 bodies are "parsed" and written;
 *  - no revision check, so a late, older response overwrites newer data;
 *  - always reports success.
 */
final class LegacyBrokenHandler implements RefreshesProfile
{
    public function execute(RefreshCommand $command): RefreshOutcome
    {
        /** @var object{id: int, handle: string} $profile */
        $profile = DB::table('profiles')->find($command->profileId);

        $response = Http::withToken(config()->string('services.upstream.token'))
            ->get(config()->string('services.upstream.base_url').'/profiles/'.$profile->handle);

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        DB::table('profiles')->where('id', $profile->id)->update([
            'likes' => $data['likes'] ?? 0,
            'revision' => $data['revision'] ?? null,
            'last_successful_refresh_at' => now(),
            'updated_at' => now(),
        ]);

        return RefreshOutcome::refreshed();
    }
}
