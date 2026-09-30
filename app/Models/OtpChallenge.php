<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpChallenge extends Model
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_APPROVAL = 'approval';

    protected $fillable = [
        'user_id', 'purpose', 'code_hash', 'context', 'attempts', 'expires_at',
        'consumed_at', 'provider_message_id', 'ip',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && now()->lessThan($this->expires_at);
    }
}
