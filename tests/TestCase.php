<?php

declare(strict_types=1);

namespace Tests;

use App\Application\Port\AccountCooldown;
use App\Application\Port\RefreshQueue;
use App\Application\Refresh\RefreshProfile;
use App\Application\Refresh\ScheduleDueRefreshes;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Tests\Support\InMemoryAccountCooldown;
use Tests\Support\RecordingLogger;
use Tests\Support\RecordingRefreshQueue;

abstract class TestCase extends BaseTestCase
{
    protected RecordingRefreshQueue $queue;

    protected InMemoryAccountCooldown $cooldown;

    protected RecordingLogger $logger;

    /**
     * Runs before RefreshDatabase wipes anything: refuse to touch a database that is not the test one.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config()->string('database.default').'.database');
        if (! is_string($database) || ! str_ends_with($database, '_testing')) {
            throw new \RuntimeException(sprintf('Refusing to run tests against database "%s".', print_r($database, true)));
        }

        return parent::setUpTraits();
    }

    /**
     * Feature tests use in-memory queue/cooldown/logger so retries can be inspected instead of
     * executed inline, and time is controlled with Carbon::setTestNow(). The real Redis and queue
     * adapters have their own integration tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new RecordingRefreshQueue;
        $this->cooldown = new InMemoryAccountCooldown;
        $this->logger = new RecordingLogger;

        $this->app->instance(RefreshQueue::class, $this->queue);
        $this->app->instance(AccountCooldown::class, $this->cooldown);
        $this->app->when([RefreshProfile::class, ScheduleDueRefreshes::class])
            ->needs(LoggerInterface::class)
            ->give(fn (): LoggerInterface => $this->logger);
    }
}
