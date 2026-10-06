<?php

namespace App\Enums;

enum ColumnType: string
{
    /** Whole numbers only: new values with a fraction are refused (values already stored stay as they are). */
    case Number = 'number';
    case Text = 'text';

    public const INTEGER_ONLY = 'در این ستون فقط عدد صحیح وارد کنید؛ اعشار مجاز نیست.';

    public function label(): string
    {
        return match ($this) {
            self::Number => 'عدد صحیح',
            self::Text => 'متن',
        };
    }
}
