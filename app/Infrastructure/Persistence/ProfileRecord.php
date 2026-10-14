<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Read model for the UI and Scout search. All writes go through DatabaseProfileRepository,
 * whose conditional statements carry the concurrency guarantees; this model never saves.
 *
 * Scout uses the "database" engine: queries run against this table, no external index to sync.
 *
 * @property int $id
 * @property string $account_id
 * @property string $handle
 * @property int|null $likes
 * @property int|null $revision
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $last_successful_refresh_at
 * @property CarbonImmutable|null $last_failure_at
 * @property string|null $last_failure_reason
 * @property string|null $last_failure_detail
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $next_refresh_at
 * @property CarbonImmutable|null $refresh_pending_since
 */
final class ProfileRecord extends Model
{
    use Searchable;

    protected $table = 'profiles';

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    public function toSearchableArray(): array
    {
        return ['handle' => $this->handle, 'account_id' => $this->account_id];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'immutable_datetime',
            'last_successful_refresh_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'next_refresh_at' => 'immutable_datetime',
            'refresh_pending_since' => 'immutable_datetime',
        ];
    }
}
