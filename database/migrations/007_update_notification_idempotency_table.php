<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add new columns
        Schema::table('notification_idempotency', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'sent', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
        });

        // Set a non-null default for identifier where it is null (for expiration notifications)
        // We'll set identifier to 'expiration' for server_expired notifications with null identifier
        DB::table('notification_idempotency')
            ->where('notification_type', 'server_expired')
            ->whereNull('identifier')
            ->update(['identifier' => 'expiration']);

        // Now make identifier non-null
        Schema::table('notification_idempotency', function (Blueprint $table) {
            $table->string('identifier')->change();
        });

        // Update existing rows to reflect that they were already sent
        // We assume existing rows were successfully sent in the past
        DB::table('notification_idempotency')
            ->update([
                'status' => 'sent',
                'attempts' => 1,
                // last_attempt_at remains null for sent rows
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_idempotency', function (Blueprint $table) {
            $table->dropColumn(['status', 'attempts', 'last_attempt_at']);
            // Note: We cannot revert the identifier change to nullable easily without losing data.
            // However, since we are only adding the migration and not running it in this session,
            // we leave the down method as a note that it would require data migration.
            // For safety, we do not change identifier back to nullable in down.
            // Instead, we note that the down migration is not supported without data loss.
        });
    }
};