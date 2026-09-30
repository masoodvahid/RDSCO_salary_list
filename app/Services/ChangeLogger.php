<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\User;

final class ChangeLogger
{
    /** @param array<string, mixed>|null $meta */
    public function record(
        int $sheetId,
        ?User $user,
        string $action,
        ?int $rowId = null,
        ?int $columnId = null,
        ?string $old = null,
        ?string $new = null,
        ?array $meta = null,
    ): ChangeLog {
        return ChangeLog::create([
            'sheet_id' => $sheetId,
            'row_id' => $rowId,
            'column_id' => $columnId,
            'user_id' => $user?->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'meta' => $meta,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
