<?php

declare(strict_types=1);

namespace App\Application\Refresh;

enum RefreshStatus: string
{
    /** A validated snapshot with a newer revision was stored. The only success. */
    case Refreshed = 'refreshed';

    /** Valid response, but the store already held this revision or a newer one (late/duplicate). */
    case Superseded = 'superseded';

    /** Transient failure; another attempt is queued. */
    case RetryScheduled = 'retry_scheduled';

    /** The account is cooling down after a 429; re-queued without calling the upstream. */
    case Deferred = 'deferred';

    /** Permanent failure or retries exhausted; the cycle ends, last valid data is kept. */
    case Failed = 'failed';

    /** The profile no longer exists locally. */
    case Skipped = 'skipped';
}
