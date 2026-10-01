<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account activity: sign-ins, wrong codes, sign-outs, invitations and changes managers make to an account.
 * (What people do inside a payroll list stays in change_logs.)
 *
 * Existing history is carried over: every used sign-in code becomes a "login" entry and every
 * invitation link an "account.link" entry, so the activity page is not empty after the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index(['actor_id', 'id']);
        });

        try {
            DB::table('otp_challenges')->select('id', 'user_id', 'ip', 'consumed_at')
                ->where('purpose', 'login')->whereNotNull('consumed_at')
                ->chunkById(1000, fn ($rows) => DB::table('user_logs')->insert($rows->map(fn ($c) => [
                    'user_id' => $c->user_id, 'actor_id' => $c->user_id, 'action' => 'login',
                    'meta' => null, 'ip' => $c->ip, 'user_agent' => null, 'created_at' => $c->consumed_at,
                ])->all()));

            DB::table('invitations')->select('id', 'user_id', 'invited_by', 'created_at')
                ->chunkById(1000, fn ($rows) => DB::table('user_logs')->insert($rows->map(fn ($i) => [
                    'user_id' => $i->user_id, 'actor_id' => $i->invited_by, 'action' => 'account.link',
                    'meta' => null, 'ip' => null, 'user_agent' => null, 'created_at' => $i->created_at,
                ])->all()));
        } catch (Throwable $e) {
            Schema::dropIfExists('user_logs');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_logs');
    }
};
