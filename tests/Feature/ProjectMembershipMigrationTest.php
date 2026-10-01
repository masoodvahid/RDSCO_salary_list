<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Upgrading an installed system: users.project_id moves into project_user (and back on rollback);
 * sign-in history and invitation links are carried into user_logs.
 * Runs on a separate scratch database so the main test database is never touched.
 */
class ProjectMembershipMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_03_000100_create_project_user_table.php';

    private string $scratch = '';

    public function test_existing_project_assignments_move_into_the_pivot_and_back(): void
    {
        $this->useScratchDatabase();
        $db = DB::connection('scratch');

        $this->migrate(before: true);
        $db->table('projects')->insert([
            ['id' => 1, 'name' => 'دماوند', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'سپهر', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $db->table('users')->insert([
            ['id' => 1, 'name' => 'مدیر', 'mobile' => '09120000000', 'role' => 'manager', 'project_id' => null, 'is_active' => 1],
            ['id' => 2, 'name' => 'ویرایشگر', 'mobile' => '09120000001', 'role' => 'editor', 'project_id' => 1, 'is_active' => 1],
            ['id' => 3, 'name' => 'تاییدکننده', 'mobile' => '09120000002', 'role' => 'approver', 'project_id' => 1, 'is_active' => 1],
            ['id' => 4, 'name' => 'تاییدکننده قدیمی', 'mobile' => '09120000003', 'role' => 'approver', 'project_id' => 1, 'is_active' => 0],
            ['id' => 5, 'name' => 'مالی', 'mobile' => '09120000004', 'role' => 'approver', 'project_id' => null, 'is_active' => 1],
        ]);

        // Sign-in history and invitation links become account activity.
        $db->table('otp_challenges')->insert([
            ['user_id' => 2, 'purpose' => 'login', 'code_hash' => 'x', 'expires_at' => now(), 'consumed_at' => '2026-09-20 08:00:00', 'ip' => '10.0.0.7', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'purpose' => 'login', 'code_hash' => 'x', 'expires_at' => now(), 'consumed_at' => null, 'ip' => '10.0.0.8', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 3, 'purpose' => 'approval', 'code_hash' => 'x', 'expires_at' => now(), 'consumed_at' => now(), 'ip' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $db->table('invitations')->insert(['user_id' => 3, 'token_hash' => str_repeat('a', 64), 'invited_by' => 1, 'expires_at' => now(), 'created_at' => '2026-09-01 09:00:00', 'updated_at' => now()]);

        $this->migrate();

        $activity = $db->table('user_logs')->orderBy('id')->get(['user_id', 'actor_id', 'action', 'ip']);
        $this->assertSame([[2, 2, 'login', '10.0.0.7'], [3, 1, 'account.link', null]], $activity->map(fn ($a) => [(int) $a->user_id, (int) $a->actor_id, $a->action, $a->ip])->all());

        $links = $db->table('project_user')->orderBy('user_id')->get(['user_id', 'project_id', 'approver_key']);
        $this->assertSame([[2, 1, null], [3, 1, 1], [4, 1, null]], $links->map(fn ($l) => [(int) $l->user_id, (int) $l->project_id, $l->approver_key === null ? null : (int) $l->approver_key])->all());
        $this->assertSame(0, $db->table('users')->whereNotNull('project_id')->count());

        Artisan::call('migrate:rollback', ['--database' => 'scratch', '--path' => [database_path('migrations/'.self::MIGRATION)], '--realpath' => true, '--force' => true]);

        $this->assertFalse(Schema::connection('scratch')->hasTable('project_user'));
        $this->assertSame([2 => 1, 3 => 1, 4 => 1], $db->table('users')->whereNotNull('project_id')->orderBy('id')->pluck('project_id', 'id')->map(fn ($v) => (int) $v)->all());
    }

    private function migrate(bool $before = false): void
    {
        $paths = collect(glob(database_path('migrations/*.php')))->sort()
            ->filter(fn ($path) => $before ? strcmp(basename($path), self::MIGRATION) < 0 : true)
            ->values()->all();
        Artisan::call('migrate', ['--database' => 'scratch', '--path' => $paths, '--realpath' => true, '--force' => true]);
    }

    private function useScratchDatabase(): void
    {
        $default = config('database.default');
        $config = config("database.connections.{$default}");

        if ($config['driver'] === 'mysql') {
            $name = $config['database'].'_migration';
            config(['database.connections.scratch_admin' => $config]);
            DB::connection('scratch_admin')->statement("DROP DATABASE IF EXISTS `{$name}`");
            DB::connection('scratch_admin')->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            config(['database.connections.scratch' => array_merge($config, ['database' => $name])]);
            $this->scratch = $name;
        } elseif ($config['driver'] === 'sqlite') {
            config(['database.connections.scratch' => array_merge($config, ['database' => ':memory:'])]);
        } else {
            $this->markTestSkipped('MySQL or SQLite only.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            DB::purge('scratch');
            DB::connection('scratch_admin')->statement("DROP DATABASE IF EXISTS `{$this->scratch}`");
        }

        parent::tearDown();
    }
}
