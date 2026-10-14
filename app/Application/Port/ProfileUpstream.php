<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Profile\AccountId;
use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\ProfileHandle;

/**
 * Driven port: the upstream profile provider. Adapters translate transport details
 * (HTTP status, timeouts, headers) into UpstreamFailure; they never decide retries.
 */
interface ProfileUpstream
{
    /**
     * @return array<mixed> The decoded JSON object, not yet validated.
     *
     * @throws UpstreamFailure When the upstream did not answer with a usable 2xx.
     * @throws InvalidProfilePayload When the 2xx body is not a JSON object.
     */
    public function fetch(AccountId $account, ProfileHandle $handle): array;
}
