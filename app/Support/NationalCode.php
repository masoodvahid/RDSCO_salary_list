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

    /**
     * Why a value is not a usable national code (in Persian, quoting the value), or null when it is valid.
     * Spaces and dashes are allowed; 8–9 digits are accepted because Excel drops leading zeros.
     */
    public static function problem(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 'کد ملی وارد نشده است';
        }

        // Spaces, dashes and invisible formatting marks (ZWNJ, RLM/LRM, BOM…) that Excel and copy-paste add.
        $digits = preg_replace('/[\s\-\p{Cf}\x{00A0}]/u', '', Digits::toEnglish($raw)) ?? '';
        if (! preg_match('/^\d+$/', $digits)) {
            return "کد ملی «{$raw}» باید فقط عدد باشد";
        }
        $length = strlen($digits);
        if ($length > 10) {
            return "کد ملی «{$raw}» بیشتر از ۱۰ رقم است (".Digits::toPersian($length).' رقم)';
        }
        if ($length < 8) {
            return "کد ملی «{$raw}» کمتر از ۱۰ رقم است (".Digits::toPersian($length).' رقم)';
        }
        $code = str_pad($digits, 10, '0', STR_PAD_LEFT);
        if (preg_match('/^(\d)\1{9}$/', $code)) {
            return "کد ملی «{$raw}» معتبر نیست (همه‌ی رقم‌ها یکسان است)";
        }
        if (! self::isValid($code)) {
            return "کد ملی «{$raw}» معتبر نیست؛ رقم کنترل (رقم آخر) با بقیه نمی‌خواند و احتمالاً یک رقم اشتباه تایپ شده";
        }

        return null;
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
