<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates table for tracking webhook delivery attempts.
 * Provides delivery tracking, retry logic, and failure reporting.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->string('event_type');
            $table->string('server_id');
            $table->json('payload');
            $table->integer('attempt')->default(1);
            $table->integer('max_attempts')->default(3);
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->integer('http_status_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            // Foreign key
            $table->foreign('webhook_endpoint_id')
                ->references('id')
                ->on('webhook_endpoints')
                ->onDelete('cascade');

            // Indexes for common queries
            $table->index(['webhook_endpoint_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['server_id', 'event_type']);
            $table->index(['queued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};