<?php

namespace App\Support;

final class NationalCode
{
    /** Normalizes to 10 Latin digits (restores leading zeros lost by Excel). Null when unusable. */
    public static function normalize(?string $value): ?string
    {
        $value = preg_replace('/\D/', '', Digits::toEnglish($value));
        if ($value === null || $value === '' || strlen($value) > 10 || strlen($value) < 8) {
            return null;
        }

        return str_pad($value, 10, '0', STR_PAD_LEFT);
    }

    public static function isValid(?string $value): bool
    {
        $code = self::normalize($value);
        if ($code === null || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $code[9];

        return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
    }

    /** Builds a valid code from 9 digits (used by seeders and tests). */
    public static function fromNineDigits(string $nine): string
    {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $nine[$i] * (10 - $i);
        }
        $remainder = $sum % 11;

        return $nine.($remainder < 2 ? $remainder : 11 - $remainder);
    }
}
