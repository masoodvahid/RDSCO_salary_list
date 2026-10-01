<?php

namespace Tests\Support;

use App\Services\Update\DatabaseBackup;

class FakeDatabaseBackup extends DatabaseBackup
{
    public ?string $restored = null;

    public function dump(string $path, ?string $connection = null): array
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "-- fake\n-- end of backup\n");

        return ['tables' => 1, 'rows' => 1, 'bytes' => (int) filesize($path)];
    }

    public function isComplete(string $path): bool
    {
        return is_file($path);
    }

    public function restore(string $path, ?string $connection = null): void
    {
        $this->restored = $path;
    }
}
