<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * This app has no users: the Horizon dashboard is only reachable in the local environment
     * (Horizon always allows local). Everywhere else the gate denies.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (mixed $user = null): bool => false);
    }
}
