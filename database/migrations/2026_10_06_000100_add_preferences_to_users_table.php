<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user screen settings, such as the column widths a user dragged in the payroll grid.
 * Additive and nullable: existing rows stay as they are (null = defaults).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
