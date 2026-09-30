<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetCell extends Model
{
    protected $fillable = ['row_id', 'column_id', 'value', 'version', 'updated_by'];

    /** Mirrors the column defaults so freshly created models match the database. */
    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(SheetRow::class, 'row_id');
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(SheetColumn::class, 'column_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
