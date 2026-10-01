<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A comment on a project's whole monthly list. */
class ListComment extends Model
{
    protected $fillable = ['sheet_project_id', 'user_id', 'body', 'in_print'];

    /** Mirrors the column defaults so freshly created models match the database. */
    protected $attributes = ['in_print' => false];

    protected function casts(): array
    {
        return ['in_print' => 'boolean'];
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
