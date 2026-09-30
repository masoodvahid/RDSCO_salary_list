<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    public const KIND_NOTE = 'note';

    public const KIND_REJECTION = 'rejection';

    protected $fillable = ['sheet_id', 'row_id', 'user_id', 'kind', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(SheetRow::class, 'row_id');
    }

    public function isRejection(): bool
    {
        return $this->kind === self::KIND_REJECTION;
    }
}
