<?php

namespace App\Models;

use App\Enums\ColumnType;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetColumn extends Model
{
    protected $fillable = ['sheet_id', 'title', 'type', 'min_value', 'max_value', 'is_locked', 'position'];

    /** Mirrors the column defaults so freshly created models match the database. */
    protected $attributes = ['type' => 'number', 'is_locked' => false, 'position' => 0];

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

    public function hasRange(): bool
    {
        return $this->isNumber() && ($this->min_value !== null || $this->max_value !== null);
    }

    /** "بین ۰ و ۳۱" / "حداقل ۰" / "حداکثر ۳۱", or null without a range. */
    public function rangeLabel(): ?string
    {
        if (! $this->hasRange()) {
            return null;
        }
        if ($this->min_value !== null && $this->max_value !== null) {
            return 'بین '.Digits::money($this->min_value).' و '.Digits::money($this->max_value);
        }

        return $this->min_value !== null
            ? 'حداقل '.Digits::money($this->min_value)
            : 'حداکثر '.Digits::money($this->max_value);
    }

    public function isOutOfRange(?string $value): bool
    {
        if ($value === null || $value === '' || ! $this->hasRange() || ! is_string(Digits::normalizeNumber($value))) {
            return false;
        }

        return ($this->min_value !== null && Digits::compare($value, $this->min_value) < 0)
            || ($this->max_value !== null && Digits::compare($value, $this->max_value) > 0);
    }

    /** The validation message for this column's range (shared by the server and the grid script). */
    public function rangeMessage(): ?string
    {
        return $this->hasRange() ? "مقدار «{$this->title}» باید {$this->rangeLabel()} باشد." : null;
    }

    /** Message for a value outside the range, or null when the value is allowed. */
    public function rangeError(?string $value): ?string
    {
        return $this->isOutOfRange($value) ? $this->rangeMessage() : null;
    }
}
