<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One sheet per Jalali month. All personnel live in it; each row carries its project.
        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('jalali_year');
            $table->unsignedTinyInteger('jalali_month');
            $table->dateTime('deadline_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['jalali_year', 'jalali_month']);
        });

        // Projects active in a month, and the approval stage of each project's part of the sheet.
        Schema::create('sheet_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('stage')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sheet_id', 'project_id']);
        });

        // Payroll items differ month to month, so columns are rows here (no ALTER TABLE per month).
        Schema::create('sheet_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('type', 10)->default('number');
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['sheet_id', 'position']);
        });

        // Personnel data is a snapshot per month so later corrections never alter approved months.
        Schema::create('sheet_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('personnel_code', 20)->nullable();
            $table->char('national_code', 10);
            $table->unsignedInteger('position')->default(0);
            $table->string('review_status', 10)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'national_code']);
            $table->index(['sheet_id', 'project_id']);
        });

        // One record per cell: per-cell audit and no lost updates when two people edit the same row.
        Schema::create('sheet_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('row_id')->constrained('sheet_rows')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('sheet_columns')->cascadeOnDelete();
            $table->string('value', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['row_id', 'column_id']);
            $table->index('updated_at');
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('row_id')->nullable()->constrained('sheet_rows')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12)->default('note');
            $table->text('body');
            $table->timestamps();

            $table->index(['sheet_id', 'row_id']);
        });

        // Append-only audit trail. Row/column ids are kept without FKs so history survives deletions.
        Schema::create('change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('row_id')->nullable()->index();
            $table->unsignedBigInteger('column_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sheet_id', 'created_at']);
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->string('code_hash');
            $table->json('context')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('provider_message_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'created_at']);
        });

        // An approval is a signature: who, when, which OTP, and a hash of exactly what was approved.
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('data_hash', 64);
            $table->foreignId('otp_challenge_id')->nullable()->constrained()->nullOnDelete();
            $table->json('skipped_stages')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['sheet_project_id', 'stage']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('change_logs');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('sheet_cells');
        Schema::dropIfExists('sheet_rows');
        Schema::dropIfExists('sheet_columns');
        Schema::dropIfExists('sheet_projects');
        Schema::dropIfExists('sheets');
    }
};
