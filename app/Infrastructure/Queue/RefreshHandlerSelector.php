<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshesProfile;
use App\Application\Refresh\RefreshOutcome;
use App\Application\Refresh\RefreshProfile;
use App\Infrastructure\Legacy\LegacyBrokenHandler;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;

/**
 * Runtime switch between the legacy handler and the fix, read per job from a shared flag.
 * Lets the workload run the SAME load "before" and "after" against running workers, and is the
 * same mechanism a production rollout would use (flip forward, flip back) without a deploy.
 */
final readonly class RefreshHandlerSelector implements RefreshesProfile
{
    public const string FLAG = 'refresh:handler';

    public const string LEGACY = 'legacy';

    public const string FIXED = 'fixed';

    public function __construct(
        private Container $container,
        private Cache $cache,
        private string $default,
    ) {}

    public function execute(RefreshCommand $command): RefreshOutcome
    {
        $handler = $this->cache->get(self::FLAG, $this->default) === self::LEGACY
            ? LegacyBrokenHandler::class
            : RefreshProfile::class;

        /** @var RefreshesProfile $refresher */
        $refresher = $this->container->make($handler);

        return $refresher->execute($command);
    }
}
