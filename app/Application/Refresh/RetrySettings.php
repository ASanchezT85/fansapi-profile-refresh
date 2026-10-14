<?php

declare(strict_types=1);

namespace App\Application\Refresh;

/**
 * Values come from config/refresh.php; see that file for what each one means.
 */
final readonly class RetrySettings
{
    public function __construct(
        public int $maxAttempts,
        public int $rateLimitBaseSeconds,
        public int $backoffBaseSeconds,
        public int $maxDelaySeconds,
        public int $deferralJitterSeconds,
        public int $exhaustedRetryDelaySeconds,
        public int $permanentFailureDelaySeconds,
    ) {}
}
