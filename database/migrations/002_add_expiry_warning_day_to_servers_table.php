<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which pre-expiration warning threshold (in days) has already been
 * sent for each server, so the every-minute warning scan stays idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (!Schema::hasColumn('servers', 'expiry_warning_day')) {
                $table->unsignedTinyInteger('expiry_warning_day')
                    ->nullable()
                    ->after('expires_at')
                    ->comment('Highest warning threshold (days before expiry) already notified');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'expiry_warning_day')) {
                $table->dropColumn('expiry_warning_day');
            }
        });
    }
};
