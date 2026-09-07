<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a suspension_reason column to the servers table to distinguish
 * between manual and expiration suspensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'suspension_reason')) {
                $table->enum('suspension_reason', ['expiration', 'manual'])->nullable()
                    ->after('expiry_warning_day')
                    ->comment('Reason for suspension: expiration or manual');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'suspension_reason')) {
                $table->dropColumn('suspension_reason');
            }
        });
    }
};