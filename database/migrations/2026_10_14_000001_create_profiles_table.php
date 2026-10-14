<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema as it was when the incident started (what LegacyBrokenHandler writes to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('account_id', 64);
            $table->string('handle', 64);
            $table->bigInteger('likes')->nullable();
            $table->bigInteger('revision')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['account_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
