<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Port\AccountCooldown;
use App\Application\Port\ProfileRepository;
use App\Application\Port\ProfileUpstream;
use App\Application\Port\RefreshQueue;
use App\Application\Refresh\RefreshesProfile;
use App\Application\Refresh\RefreshProfile;
use App\Application\Refresh\RetryPolicy;
use App\Application\Refresh\RetrySettings;
use App\Application\Refresh\ScheduleDueRefreshes;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Http\HttpProfileUpstream;
use App\Infrastructure\Persistence\DatabaseProfileRepository;
use App\Infrastructure\Queue\LaravelRefreshQueue;
use App\Infrastructure\Queue\RefreshHandlerSelector;
use App\Infrastructure\Redis\RedisAccountCooldown;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Composition root of the hexagon: the only place that knows which adapter backs which port.
 */
final class RefreshServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->bind(ProfileRepository::class, DatabaseProfileRepository::class);
        $this->app->bind(RefreshQueue::class, LaravelRefreshQueue::class);
        // Jobs go through the runtime flag (legacy|fixed); see RefreshHandlerSelector.
        $this->app->bind(RefreshesProfile::class, RefreshHandlerSelector::class);
        $this->app->when(RefreshHandlerSelector::class)->needs('$default')->giveConfig('refresh.handler');

        $this->app->bind(ProfileUpstream::class, fn (Application $app): ProfileUpstream => new HttpProfileUpstream(
            $app->make(HttpFactory::class),
            config()->string('services.upstream.base_url'),
            config()->string('services.upstream.token'),
            config()->float('refresh.upstream.timeout_seconds'),
            config()->float('refresh.upstream.connect_timeout_seconds'),
            config()->integer('refresh.upstream.max_body_bytes'),
        ));

        $this->app->bind(AccountCooldown::class, fn (Application $app): AccountCooldown => new RedisAccountCooldown(
            $app->make('redis')->connection(),
        ));

        $this->app->bind(RetryPolicy::class, fn (): RetryPolicy => new RetryPolicy(
            new RetrySettings(
                maxAttempts: config()->integer('refresh.retry.max_attempts'),
                rateLimitBaseSeconds: config()->integer('refresh.retry.rate_limit_base_seconds'),
                backoffBaseSeconds: config()->integer('refresh.retry.backoff_base_seconds'),
                maxDelaySeconds: config()->integer('refresh.retry.max_delay_seconds'),
                deferralJitterSeconds: config()->integer('refresh.retry.deferral_jitter_seconds'),
                exhaustedRetryDelaySeconds: config()->integer('refresh.retry.exhausted_retry_delay_seconds'),
                permanentFailureDelaySeconds: config()->integer('refresh.retry.permanent_failure_delay_seconds'),
            ),
            new Randomizer,
        ));

        $this->app->when(ScheduleDueRefreshes::class)
            ->needs('$pendingTimeoutSeconds')
            ->giveConfig('refresh.scheduling.pending_timeout_seconds');

        $this->app->when([RefreshProfile::class, ScheduleDueRefreshes::class])
            ->needs(LoggerInterface::class)
            ->give(fn (): LoggerInterface => Log::channel('refresh'));
    }
}
