<?php

namespace Tests\Feature;

use App\Models\SheetCell;
use App\Models\User;
use App\Services\Update\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/**
 * Dump on the test database, restore into a separate database (so the test transaction
 * is never committed by DDL). Runs where tests use MySQL (CI); skipped on SQLite.
 */
class DatabaseBackupTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    public function test_mysql_dump_restores_into_an_empty_database(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // CI runs the suite on MySQL; never let this test be skipped there silently.
            $this->assertNotSame('true', getenv('GITHUB_ACTIONS'), 'CI must run DatabaseBackupTest on MySQL.');
            $this->markTestSkipped('MySQL only.');
        }

        $this->buildSheet();
        $approver = User::factory()->approver($this->projectA)->create(['job_title' => 'مدیر "داخلی" پروژه']);
        $tricky = "a'b\"c\\d\nخط دوم ۱۲۳; DROP TABLE x; --";
        $cell = SheetCell::create(['row_id' => $this->rowA->id, 'column_id' => $this->textColumn->id, 'value' => $tricky, 'version' => 1]);

        $path = sys_get_temp_dir().'/tuka-backup-'.uniqid().'.sql.gz';
        $backup = app(DatabaseBackup::class);
        $info = $backup->dump($path);

        $this->assertTrue($backup->isComplete($path));
        $this->assertGreaterThan(5, $info['tables']);

        $config = config('database.connections.mysql');
        $name = $config['database'].'_restore';
        config([
            'database.connections.restore_admin' => $config,
            'database.connections.restore' => array_merge($config, ['database' => $name]),
        ]);
        $admin = DB::connection('restore_admin');
        $admin->statement("DROP DATABASE IF EXISTS `{$name}`");
        $admin->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            DB::connection('restore')->statement('CREATE TABLE leftover (id INT)');

            $backup->restore($path, 'restore');
            $restored = DB::connection('restore');

            $this->assertFalse(Schema::connection('restore')->hasTable('leftover'));
            $this->assertSame(DB::table('sheet_rows')->count(), $restored->table('sheet_rows')->count());
            $this->assertSame(DB::table('users')->count(), $restored->table('users')->count());
            $this->assertSame($tricky, $restored->table('sheet_cells')->where('id', $cell->id)->value('value'));
            $this->assertSame('مدیر "داخلی" پروژه', $restored->table('users')->where('id', $approver->id)->value('job_title'));
            // Project membership and the approver slot come back too.
            $this->assertEquals($this->projectA->id, $restored->table('project_user')->where('user_id', $approver->id)->value('approver_key'));
            // The legacy stored generated column is skipped by the dump and recomputed by MySQL.
            $this->assertTrue(Schema::connection('restore')->hasColumn('users', 'approver_project_key'));
        } finally {
            DB::purge('restore');
            $admin->statement("DROP DATABASE IF EXISTS `{$name}`");
            @unlink($path);
        }
    }

    public function test_an_unfinished_dump_is_not_restored(): void
    {
        $path = sys_get_temp_dir().'/tuka-partial-'.uniqid().'.sql.gz';
        $gz = gzopen($path, 'wb');
        gzwrite($gz, "SET NAMES utf8mb4;\nDROP TABLE IF EXISTS `users`;\n");
        gzclose($gz);

        $this->assertFalse(app(DatabaseBackup::class)->isComplete($path));
        @unlink($path);
    }
}
