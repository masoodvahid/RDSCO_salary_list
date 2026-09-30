<?php

namespace App\Models;

use App\Support\Digits;
use App\Support\Jalali;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sheet extends Model
{
    protected $fillable = ['jalali_year', 'jalali_month', 'deadline_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'jalali_year' => 'integer',
            'jalali_month' => 'integer',
            'deadline_at' => 'datetime',
        ];
    }

    public function columns(): HasMany
    {
        return $this->hasMany(SheetColumn::class)->orderBy('position')->orderBy('id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SheetRow::class);
    }

    public function sheetProjects(): HasMany
    {
        return $this->hasMany(SheetProject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "شهریور ۱۴۰۵" */
    public function title(): string
    {
        return Jalali::monthName($this->jalali_month).' '.Digits::toPersian($this->jalali_year);
    }

    public function isPastDeadline(?CarbonInterface $now = null): bool
    {
        return ($now ?? now())->greaterThan($this->deadline_at);
    }

    public function daysLeft(): int
    {
        return (int) max(0, ceil(now()->diffInHours($this->deadline_at, false) / 24));
    }
}
