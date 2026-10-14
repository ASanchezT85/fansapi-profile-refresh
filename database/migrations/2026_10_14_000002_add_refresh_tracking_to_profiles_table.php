<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the fix: separate "last attempt", "last success" and "last failure", track scheduling
 * state, and let the database itself refuse invalid likes/revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->renameColumn('refreshed_at', 'last_successful_refresh_at');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->jsonb('profile_data')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->string('last_failure_reason', 32)->nullable();
            $table->string('last_failure_detail', 500)->nullable();
            $table->integer('consecutive_failures')->default(0);
            // NULL = due now (never refreshed).
            $table->timestampTz('next_refresh_at')->nullable();
            // NULL = not queued. Set atomically when the scheduler claims the profile.
            $table->timestampTz('refresh_pending_since')->nullable();

            $table->index('next_refresh_at');
            $table->index('refresh_pending_since');
        });

        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_likes_non_negative CHECK (likes >= 0)');
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_revision_non_negative CHECK (revision >= 0)');

        Schema::create('refresh_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 64);
            $table->smallInteger('attempt');
            $table->smallInteger('delivery');
            $table->uuid('job_id')->nullable();
            $table->string('outcome', 32);
            $table->smallInteger('http_status')->nullable();
            $table->bigInteger('revision')->nullable();
            $table->integer('duration_ms');
            $table->timestampTz('created_at');

            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_attempts');
        DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_likes_non_negative');
        DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_revision_non_negative');

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex(['next_refresh_at']);
            $table->dropIndex(['refresh_pending_since']);
            $table->dropColumn([
                'profile_data', 'last_attempt_at', 'last_failure_at', 'last_failure_reason',
                'last_failure_detail', 'consecutive_failures', 'next_refresh_at', 'refresh_pending_since',
            ]);
            $table->renameColumn('last_successful_refresh_at', 'refreshed_at');
        });
    }
};
