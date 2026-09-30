<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('mobile', 11)->unique();
            $table->string('role', 16)->default('viewer');
            // null = access to every project (managers, finance, CEO); otherwise one project.
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // At most one active approver per project, enforced by the database (MySQL has no partial indexes).
            // A stored generated column may reference project_id because its FK uses RESTRICT, not CASCADE/SET NULL.
            $table->unsignedBigInteger('approver_project_key')->nullable()->storedAs(
                "CASE WHEN role = 'approver' AND is_active = 1 AND project_id IS NOT NULL THEN project_id ELSE NULL END"
            );
            $table->unique('approver_project_key');
            $table->index(['role', 'is_active']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('projects');
    }
};
