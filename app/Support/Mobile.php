<?php

namespace App\Support;

final class Mobile
{
    /** "+98 912 123 4567" / "۰۹۱۲..." → "09121234567" (unchanged when it cannot be normalized). */
    public static function normalize(?string $value): string
    {
        $digits = preg_replace('/\D/', '', Digits::toEnglish($value)) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    public static function isValid(?string $value): bool
    {
        return (bool) preg_match('/^09\d{9}$/', (string) $value);
    }

    /** "0912•••4521" */
    public static function mask(?string $value): string
    {
        $value = (string) $value;

        return strlen($value) === 11 ? substr($value, 0, 4).'•••'.substr($value, -4) : $value;
    }
}
