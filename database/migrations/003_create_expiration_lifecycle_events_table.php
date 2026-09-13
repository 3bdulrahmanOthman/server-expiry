<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates table for tracking expiration lifecycle events.
 * Provides audit trail for all expiration-related changes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expiration_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->string('server_id')->index();
            $table->string('event_type')->index();
            $table->json('event_data')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            // Indexes for common queries
            $table->index(['server_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expiration_lifecycle_events');
    }
};