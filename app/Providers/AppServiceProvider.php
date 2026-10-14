<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Refresh\RefreshesProfile;
use App\Infrastructure\Legacy\LegacyBrokenHandler;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RefreshesProfile::class, LegacyBrokenHandler::class);
    }
}
