<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Local upstream responses (tests/Fixtures/upstream) and the incident's starting profile.
 */
trait UpstreamFixtures
{
    protected const string ACCOUNT = 'acct-main';

    protected const string HANDLE = 'madison420ivy';

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/upstream/'.$name));
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function upstreamResponds(int $status, string $body = '', array $headers = []): void
    {
        Http::fake(['*' => Http::response($body, $status, $headers + ['Content-Type' => 'application/json'])]);
    }

    /**
     * Responses served in order, one per request.
     */
    protected function upstreamRespondsInOrder(string ...$fixtures): void
    {
        $sequence = Http::sequence();
        foreach ($fixtures as $fixture) {
            $sequence->push($this->fixture($fixture), 200, ['Content-Type' => 'application/json']);
        }

        Http::fake(['*' => $sequence]);
    }

    protected function upstreamTimesOut(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received')]);
    }

    /**
     * The profile from the statement: last valid likes 120,000 at revision 10.
     */
    protected function seedProfile(?int $likes = 120_000, ?int $revision = 10, string $handle = self::HANDLE): int
    {
        return DB::table('profiles')->insertGetId([
            'account_id' => self::ACCOUNT,
            'handle' => $handle,
            'likes' => $likes,
            'revision' => $revision,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{likes: int|null, revision: int|null}
     */
    protected function storedProfile(int $id): array
    {
        /** @var object{likes: int|null, revision: int|null} $row */
        $row = DB::table('profiles')->find($id);

        return ['likes' => $row->likes, 'revision' => $row->revision];
    }
}
