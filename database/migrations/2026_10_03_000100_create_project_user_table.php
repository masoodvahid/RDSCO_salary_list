<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A member can now belong to several projects.
 *
 * Each user's single users.project_id moves into the project_user pivot. users.project_id is
 * cleared but kept (no column drop), so `down()` can restore it; the app no longer reads it.
 *
 * "One active approver per project" moves with it: approver_key holds the project id while the
 * user is an active approver (NULL otherwise) and is unique, so the database still refuses a
 * second approver on the same project. The User model keeps approver_key in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('approver_key')->nullable()->unique();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        try {
            DB::transaction(function () {
                $now = now();
                DB::table('users')->select('id', 'project_id', 'role', 'is_active')->whereNotNull('project_id')
                    ->chunkById(500, function ($users) use ($now) {
                        DB::table('project_user')->insert($users->map(fn ($user) => [
                            'project_id' => $user->project_id,
                            'user_id' => $user->id,
                            'approver_key' => $user->role === 'approver' && (bool) $user->is_active ? $user->project_id : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all());
                    });

                DB::table('users')->whereNotNull('project_id')->update(['project_id' => null]);
            });
        } catch (Throwable $e) {
            Schema::dropIfExists('project_user');

            throw $e;
        }
    }

    public function down(): void
    {
        // Back to one project per user: keep the first one.
        $first = DB::table('project_user')->select('user_id', DB::raw('min(project_id) as project_id'))->groupBy('user_id')->get();
        foreach ($first as $link) {
            DB::table('users')->where('id', $link->user_id)->update(['project_id' => $link->project_id]);
        }

        Schema::dropIfExists('project_user');
    }
};
