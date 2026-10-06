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

    /**
     * Whole number for number columns: canonical integer string, null when empty, false when not a whole
     * number. "1,500,000" and "12.00" are accepted (12.00 is 12); "12.5" is not.
     */
    public static function normalizeInteger(?string $value): string|false|null
    {
        $number = self::normalizeNumber($value);

        return is_string($number) && str_contains($number, '.') ? false : $number;
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

    /**
     * Exact comparison of two numeric strings (any length, no float rounding): -1, 0 or 1.
     * Inputs are normalized first; a non-numeric input counts as 0.
     */
    public static function compare(string $a, string $b): int
    {
        $a = self::normalizeNumber($a);
        $b = self::normalizeNumber($b);
        $a = is_string($a) ? $a : '0';
        $b = is_string($b) ? $b : '0';

        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        [$intA, $fracA] = array_pad(explode('.', ltrim($a, '-'), 2), 2, '');
        [$intB, $fracB] = array_pad(explode('.', ltrim($b, '-'), 2), 2, '');
        $length = max(strlen($fracA), strlen($fracB));

        $result = strlen($intA) <=> strlen($intB)
            ?: strcmp($intA, $intB) <=> 0
            ?: strcmp(str_pad($fracA, $length, '0'), str_pad($fracB, $length, '0')) <=> 0;

        return $negativeA ? -$result : $result;
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

    /**
     * Exact sum of numeric strings, same result as chaining add(); empty and non-numeric values are skipped.
     * Plain integers (what payroll amounts are) are added natively, which keeps the column totals of a
     * big list cheap; anything else goes through add().
     *
     * @param  iterable<int, string|null>  $values
     */
    public static function sum(iterable $values): string
    {
        $integers = 0;
        $rest = '0';
        foreach ($values as $value) {
            $value = (string) $value;
            if (preg_match('/^-?\d{1,15}\z/', $value)) {
                $integers += (int) $value;
                // Far below PHP_INT_MAX, so adding another 15-digit value can never overflow.
                if ($integers > 1e17 || $integers < -1e17) {
                    $rest = self::add($rest, (string) $integers);
                    $integers = 0;
                }

                continue;
            }
            $number = self::normalizeNumber($value);
            if (is_string($number)) {
                $rest = self::add($rest, $number);
            }
        }

        return self::add((string) $integers, $rest);
    }
}
