<?php

declare(strict_types=1);

use App\Infrastructure\FakeUpstream\FakeUpstreamController;
use Illuminate\Support\Facades\Route;

// Local fake provider for the repeatable workload. Loaded only when FAKE_UPSTREAM_ENABLED=true,
// without the web middleware group (no session/cookies), like a real JSON API.
Route::get('/fake-upstream/profiles/{handle}', FakeUpstreamController::class);
