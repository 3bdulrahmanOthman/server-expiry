<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `expires_at` timestamp column to Pelican's `servers` table.
 *
 * Numeric filename prefix (001_) keeps plugin migration ordering stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'expires_at')) {
                $table->timestamp('expires_at')
                    ->nullable()
                    ->after('installed_at')
                    ->comment('Expiration timestamp after which server auto-suspension takes place.');

                $table->index('expires_at', 'servers_expires_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'expires_at')) {
                $table->dropIndex('servers_expires_at_index');
                $table->dropColumn('expires_at');
            }
        });
    }
};
