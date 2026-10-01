<?php

namespace App\Services\Update;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Database backup without shell access (shared hosts usually disable exec/mysqldump).
 *
 * MySQL/MariaDB: a gzip'd SQL dump written with PDO, one statement per line.
 * SQLite (file): a copy of the database file.
 */
class DatabaseBackup
{
    private const ROWS_PER_INSERT = 200;

    /** @return array{tables: int, rows: int, bytes: int} */
    public function dump(string $path, ?string $connection = null): array
    {
        $db = DB::connection($connection);
        @mkdir(dirname($path), 0775, true);

        if ($db->getDriverName() === 'sqlite') {
            return $this->copySqlite($db, $path);
        }
        $this->assertMysql($db);

        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new UpdateException('فایل بکاپ دیتابیس ساخته نشد؛ دسترسی نوشتن پوشه storage را بررسی کنید.');
        }

        $pdo = $db->getPdo();
        $tables = 0;
        $rows = 0;

        try {
            gzwrite($gz, "-- TukaHR database backup\n-- ".now()->toDateTimeString()."\n");
            gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");

            foreach ($this->tables($db) as $table) {
                $tables++;
                $create = (array) $db->selectOne('SHOW CREATE TABLE '.$this->quoteName($table));
                $statement = str_replace(["\r\n", "\n"], ' ', (string) ($create['Create Table'] ?? array_values($create)[1]));
                gzwrite($gz, 'DROP TABLE IF EXISTS '.$this->quoteName($table).";\n{$statement};\n");

                // Generated columns are computed by MySQL and cannot be inserted.
                $columns = collect($db->select(
                    'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                    [$table],
                ))->reject(fn ($c) => str_contains(strtoupper((string) $c->EXTRA), 'GENERATED'))->pluck('COLUMN_NAME')->all();

                $list = implode(', ', array_map(fn ($c) => $this->quoteName($c), $columns));

                // Stream rows (unbuffered) so large tables do not have to fit in memory.
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $result = $pdo->query("SELECT {$list} FROM ".$this->quoteName($table), PDO::FETCH_NUM);
                    $batch = [];
                    foreach ($result as $row) {
                        $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                        $rows++;
                        if (count($batch) === self::ROWS_PER_INSERT) {
                            gzwrite($gz, 'INSERT INTO '.$this->quoteName($table)." ({$list}) VALUES ".implode(', ', $batch).";\n");
                            $batch = [];
                        }
                    }
                    $result->closeCursor();
                } finally {
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
                if ($batch !== []) {
                    gzwrite($gz, 'INSERT INTO '.$this->quoteName($table)." ({$list}) VALUES ".implode(', ', $batch).";\n");
                }
            }

            gzwrite($gz, "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
        } finally {
            gzclose($gz);
        }

        return ['tables' => $tables, 'rows' => $rows, 'bytes' => (int) filesize($path)];
    }

    /**
     * Restores a dump made by dump(). Tables that are not in the dump (for example created by
     * a migration that failed halfway) are dropped, so the schema matches the backup exactly.
     */
    public function restore(string $path, ?string $connection = null): void
    {
        $db = DB::connection($connection);
        if (! is_file($path)) {
            throw new UpdateException('فایل بکاپ دیتابیس پیدا نشد.');
        }

        if ($db->getDriverName() === 'sqlite') {
            $this->restoreSqlite($db, $path);

            return;
        }
        $this->assertMysql($db);

        if (! $this->isComplete($path)) {
            throw new UpdateException('فایل بکاپ دیتابیس ناقص است و بازگردانی نشد.');
        }

        $pdo = $db->getPdo();
        $inBackup = [];
        $gz = gzopen($path, 'rb');
        while (($line = gzgets($gz)) !== false) {
            if (preg_match('/^DROP TABLE IF EXISTS `((?:[^`]|``)+)`;/', $line, $m)) {
                $inBackup[] = str_replace('``', '`', $m[1]);
            }
        }
        gzclose($gz);

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_diff($this->tables($db), $inBackup) as $extra) {
            $pdo->exec('DROP TABLE IF EXISTS '.$this->quoteName($extra));
        }

        $gz = gzopen($path, 'rb');
        $buffer = '';
        try {
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($buffer === '' && ($line === '' || str_starts_with($line, '--'))) {
                    continue;
                }
                $buffer .= $line;
                if (str_ends_with($line, ';')) {
                    $pdo->exec($buffer);
                    $buffer = '';
                }
            }
        } finally {
            gzclose($gz);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** A dump is complete only if it reached its closing line. */
    public function isComplete(string $path): bool
    {
        if (str_ends_with($path, '.sqlite')) {
            return is_file($path) && filesize($path) > 0;
        }
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            return false;
        }
        $last = '';
        while (($line = gzgets($gz)) !== false) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }
        gzclose($gz);

        return $last === '-- end of backup';
    }

    /** @return list<string> */
    private function tables(Connection $db): array
    {
        return collect($db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn ($row) => (string) array_values((array) $row)[0])
            ->values()
            ->all();
    }

    private function quoteName(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function assertMysql(Connection $db): void
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new UpdateException('بکاپ خودکار فقط برای MySQL/MariaDB و SQLite پشتیبانی می‌شود.');
        }
    }

    /** @return array{tables: int, rows: int, bytes: int} */
    private function copySqlite(Connection $db, string $path): array
    {
        $file = (string) $db->getConfig('database');
        if ($file === ':memory:' || ! is_file($file)) {
            throw new UpdateException('دیتابیس SQLite در حافظه است و بکاپ فایلی ندارد.');
        }
        copy($file, $path);

        return ['tables' => 0, 'rows' => 0, 'bytes' => (int) filesize($path)];
    }

    private function restoreSqlite(Connection $db, string $path): void
    {
        $file = (string) $db->getConfig('database');
        $db->disconnect();
        copy($path, $file);
    }
}
