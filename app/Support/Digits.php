<?php

namespace App\Support;

final class Digits
{
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const LATIN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function toEnglish(?string $value): string
    {
        return str_replace([...self::PERSIAN, ...self::ARABIC], [...self::LATIN, ...self::LATIN], (string) $value);
    }

    public static function toPersian(string|int|float|null $value): string
    {
        return str_replace(self::LATIN, self::PERSIAN, (string) $value);
    }

    /**
     * Canonical numeric string ("-1234.5") or null when empty.
     * Accepts Persian/Arabic digits and thousands separators. Returns false when not numeric.
     */
    public static function normalizeNumber(?string $value): string|false|null
    {
        $value = trim(self::toEnglish($value));
        $value = str_replace([',', '٬', '،', ' ', "\u{00A0}", "\u{200C}"], '', $value);
        $value = str_replace('٫', '.', $value);

        if ($value === '') {
            return null;
        }
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return false;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, null);
        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        $frac = $frac === null ? null : rtrim($frac, '0');
        $result = $int.($frac ? '.'.$frac : '');

        return ($negative && $result !== '0') ? '-'.$result : $result;
    }

    /** 185000000 → "185,000,000" (Latin digits, used inside the grid). */
    public static function group(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (! preg_match('/^(-?)(\d+)(\.\d+)?$/', $value, $m)) {
            return $value;
        }

        return $m[1].strrev(implode(',', str_split(strrev($m[2]), 3))).($m[3] ?? '');
    }

    /** Persian digits with Persian thousands separator, for UI text. */
    public static function money(string|int|float|null $value): string
    {
        return self::toPersian(str_replace(',', '٬', self::group((string) $value)));
    }

    /** Adds two canonical numeric strings without float drift when bcmath is available. */
    public static function add(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            $sum = bcadd($a, $b, 4);
        } else {
            $sum = (string) ((float) $a + (float) $b);
        }

        $normalized = self::normalizeNumber($sum);

        return is_string($normalized) ? $normalized : '0';
    }
}
