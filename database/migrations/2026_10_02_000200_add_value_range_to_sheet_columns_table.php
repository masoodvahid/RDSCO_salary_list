<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_columns', function (Blueprint $table) {
            // Optional bounds for number columns, stored in the same canonical form as cell values
            // ("-1234.5"). Null means no limit on that side.
            $table->string('min_value', 40)->nullable()->after('type');
            $table->string('max_value', 40)->nullable()->after('min_value');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_columns', function (Blueprint $table) {
            $table->dropColumn(['min_value', 'max_value']);
        });
    }
};
