<?php

namespace App\Enums;

enum ColumnType: string
{
    case Number = 'number';
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Number => 'عدد',
            self::Text => 'متن',
        };
    }
}
