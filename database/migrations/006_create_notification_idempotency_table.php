<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates table for tracking notification idempotency.
 * Ensures notifications are not sent repeatedly for the same server,
 * event type, and threshold combination.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(notification_idempotency, function (Blueprint $table) {
            $table->id();
            $table->string(server_id);
            $table->string(notification_type); // e.g., expiry_warning, server_expired
            $table->string(identifier)->nullable(); // e.g., warning threshold days, or null for one-time events
            $table->timestamp(sent_at)->useCurrent();

            // Composite unique key to prevent duplicate notifications
            $table->unique([server_id, notification_type, identifier]);

            // Indexes for cleanup queries
            $table->index([server_id, notification_type]);
            $table->index([sent_at]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(notification_idempotency);
    }
};
