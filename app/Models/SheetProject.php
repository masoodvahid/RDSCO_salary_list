<?php

namespace App\Models;

use App\Enums\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetProject extends Model
{
    protected $fillable = ['sheet_id', 'project_id', 'stage', 'submitted_at', 'submitted_by'];

    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'submitted_at' => 'datetime',
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

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('id');
    }

    public function activeApprovals(): HasMany
    {
        return $this->approvals()->whereNull('revoked_at');
    }
}
