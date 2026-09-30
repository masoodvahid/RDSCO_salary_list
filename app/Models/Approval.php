<?php

namespace App\Models;

use App\Enums\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'sheet_project_id', 'stage', 'user_id', 'data_hash', 'otp_challenge_id',
        'skipped_stages', 'ip', 'user_agent', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'skipped_stages' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sheetProject(): BelongsTo
    {
        return $this->belongsTo(SheetProject::class);
    }
}
