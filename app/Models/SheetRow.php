<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetRow extends Model
{
    /** Personnel fields stored on the row itself (always manager-only). */
    public const IDENTITY_FIELDS = ['first_name', 'last_name', 'personnel_code', 'national_code'];

    protected $fillable = [
        'sheet_id', 'project_id', 'first_name', 'last_name', 'personnel_code', 'national_code',
        'position', 'review_status', 'reviewed_by', 'reviewed_at',
    ];

    /** Mirrors the column defaults so freshly created models match the database. */
    protected $attributes = ['review_status' => 'pending', 'position' => 0];

    protected function casts(): array
    {
        return [
            'review_status' => ReviewStatus::class,
            'reviewed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cells(): HasMany
    {
        return $this->hasMany(SheetCell::class, 'row_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'row_id')->latest('id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
