<?php

declare(strict_types=1);

/*
 * Profile refresh settings. Local defaults are deliberately small so the workload finishes in
 * seconds; production values belong in the environment.
 *
 * Timeout chain (must hold, see README "Timeouts"):
 *   upstream.timeout_seconds (5) < queue.job_timeout_seconds (30)
 *   < queue.overlap_lock_seconds (40) < REDIS_QUEUE_RETRY_AFTER (90, config/queue.php)
 */
return [

    // Default handler when the runtime flag (cache key "refresh:handler") is not set: fixed|legacy.
    // "legacy" exists only to reproduce the incident and measure the "before" numbers.
    'handler' => env('REFRESH_HANDLER', 'fixed'),

    // Local-only crash switch for `php artisan refresh:crash-demo` (kills the worker after the DB write).
    'chaos' => [
        'enabled' => (bool) env('REFRESH_CHAOS_ENABLED', false),
    ],

    'upstream' => [
        'timeout_seconds' => (float) env('UPSTREAM_TIMEOUT_SECONDS', 5),
        'connect_timeout_seconds' => (float) env('UPSTREAM_CONNECT_TIMEOUT_SECONDS', 2),
        // A profile is a few KB. Anything bigger is not a profile and is not worth holding in a worker's RAM.
        'max_body_bytes' => (int) env('UPSTREAM_MAX_BODY_BYTES', 1_048_576),
    ],

    'retry' => [
        // Upstream attempts per refresh cycle (first try included).
        'max_attempts' => (int) env('REFRESH_MAX_ATTEMPTS', 5),
        // 429 without Retry-After: random delay in [base * 2^(attempt-1), 2x that].
        'rate_limit_base_seconds' => (int) env('REFRESH_RATE_LIMIT_BASE_SECONDS', 2),
        // Timeouts / 5xx: exponential backoff from this base, with jitter.
        'backoff_base_seconds' => (int) env('REFRESH_BACKOFF_BASE_SECONDS', 1),
        'max_delay_seconds' => (int) env('REFRESH_MAX_DELAY_SECONDS', 60),
        // Spread for jobs deferred behind an account cooldown.
        'deferral_jitter_seconds' => (int) env('REFRESH_DEFERRAL_JITTER_SECONDS', 2),
        // When the cycle ends without success: retry later instead of on the next scheduler tick.
        'exhausted_retry_delay_seconds' => (int) env('REFRESH_EXHAUSTED_RETRY_DELAY_SECONDS', 900),
        'permanent_failure_delay_seconds' => (int) env('REFRESH_PERMANENT_FAILURE_DELAY_SECONDS', 86_400),
    ],

    'scheduling' => [
        'batch_size' => (int) env('REFRESH_SCHEDULE_BATCH_SIZE', 500),
        // Longer than a full retry cycle; after this a pending mark is considered lost and reclaimed.
        'pending_timeout_seconds' => (int) env('REFRESH_PENDING_TIMEOUT_SECONDS', 900),
    ],

    'queue' => [
        'name' => env('REFRESH_QUEUE', 'refresh'),
        'job_timeout_seconds' => (int) env('REFRESH_JOB_TIMEOUT', 30),
        // Queue deliveries (crash/timeout redelivery), not upstream retries.
        'job_deliveries' => (int) env('REFRESH_JOB_DELIVERIES', 3),
        // Per-profile lock so two workers never run the same profile at once.
        'overlap_lock_seconds' => (int) env('REFRESH_OVERLAP_LOCK_SECONDS', 40),
    ],

];
