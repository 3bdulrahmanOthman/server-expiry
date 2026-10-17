<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit, recoverable delivery claim to webhook_deliveries.
 *
 * A worker claims a due row with an atomic conditional UPDATE before making the
 * outbound HTTP request, so two overlapping workers can never both send the
 * same delivery. `claim_token` identifies the owning worker and `claimed_at`
 * records when the lease started; a lease that outlives the delivery worker (a
 * crash) is reclaimable once it expires.
 *
 * The claim is deliberately stored in dedicated columns rather than overloading
 * `next_attempt_at` (which stays the retry schedule) or altering the `status`
 * enum. On SQLite the enum is a `varchar ... check (status in (...))` and on
 * MySQL a native `enum(...)`; widening either is a driver-specific table rebuild
 * (SQLiteGrammar::compileChange is a no-op) that this plugin cannot verify
 * against a live MySQL server, so "in flight" is expressed by a live claim
 * instead of a fourth enum value.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            // Identifies the worker that owns the current claim. Only that worker
            // may finalize the row, so a lease that expired mid-request cannot let
            // a late response overwrite a newer worker's result.
            $table->string('claim_token', 64)->nullable()->after('next_attempt_at');

            // When the current claim's lease began. Null means "not being worked".
            $table->timestamp('claimed_at')->nullable()->after('claim_token');

            // Supports the claim/recovery poll: find due rows whose lease is free
            // or expired, plus stale never-claimed rows.
            $table->index(['status', 'next_attempt_at', 'claimed_at'], 'webhook_deliveries_claim_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('webhook_deliveries_claim_idx');
            $table->dropColumn(['claim_token', 'claimed_at']);
        });
    }
};
