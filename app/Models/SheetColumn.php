<?php

namespace App\Models;

use App\Enums\ColumnType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetColumn extends Model
{
    protected $fillable = ['sheet_id', 'title', 'type', 'is_locked', 'position'];

    protected function casts(): array
    {
        return [
            'type' => ColumnType::class,
            'is_locked' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    public function isNumber(): bool
    {
        return $this->type === ColumnType::Number;
    }
}
