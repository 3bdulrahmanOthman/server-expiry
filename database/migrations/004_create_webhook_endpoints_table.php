<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates table for storing webhook endpoint configurations.
 * Each endpoint can be configured for specific expiration events.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('secret_key')->nullable();
            $table->json('events')->default('[]'); // List of events to listen to
            $table->boolean('active')->default(true);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['active']);
            $table->index(['url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};