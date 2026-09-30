<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Jalali (Persian) calendar conversion — a port of the jalaali-js algorithm.
 */
final class Jalali
{
    private const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    private const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /** @return array{0:int,1:int,2:int} [jy, jm, jd] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        return self::d2j(self::g2d($gy, $gm, $gd));
    }

    /** @return array{0:int,1:int,2:int} [gy, gm, gd] */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        if (! self::isValid($jy, $jm, $jd)) {
            throw new InvalidArgumentException("Invalid Jalali date {$jy}/{$jm}/{$jd}");
        }

        return self::d2g(self::j2d($jy, $jm, $jd));
    }

    /** @return array{0:int,1:int,2:int} */
    public static function fromCarbon(CarbonInterface $date): array
    {
        return self::fromGregorian((int) $date->year, (int) $date->month, (int) $date->day);
    }

    public static function toCarbon(int $jy, int $jm, int $jd, ?string $timezone = null): CarbonImmutable
    {
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, $timezone ?? config('app.timezone', 'UTC'));
    }

    public static function isLeap(int $jy): bool
    {
        return self::jalCal($jy)['leap'] === 0;
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }

        return self::isLeap($jy) ? 30 : 29;
    }

    public static function isValid(int $jy, int $jm, int $jd): bool
    {
        return $jy >= -61 && $jy <= 3177 && $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::monthLength($jy, $jm);
    }

    public static function monthName(int $jm): string
    {
        return self::MONTHS[$jm] ?? (string) $jm;
    }

    /** @return array<int, string> */
    public static function monthNames(): array
    {
        return self::MONTHS;
    }

    /** @return array{0:int,1:int} */
    public static function nextMonth(int $jy, int $jm): array
    {
        return $jm === 12 ? [$jy + 1, 1] : [$jy, $jm + 1];
    }

    /** @return array{0:int,1:int} */
    public static function previousMonth(int $jy, int $jm): array
    {
        return $jm === 1 ? [$jy - 1, 12] : [$jy, $jm - 1];
    }

    /** "۱۴ مهر ۱۴۰۵" */
    public static function formatLong(?CarbonInterface $date): string
    {
        if (! $date) {
            return '';
        }
        [$jy, $jm, $jd] = self::fromCarbon($date->copy()->setTimezone(config('app.timezone', 'UTC')));

        return Digits::toPersian("{$jd} ".self::monthName($jm)." {$jy}");
    }

    /** "1405/07/14" */
    public static function formatShort(?CarbonInterface $date): string
    {
        if (! $date) {
            return '';
        }
        [$jy, $jm, $jd] = self::fromCarbon($date->copy()->setTimezone(config('app.timezone', 'UTC')));

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /** Parses "1405/07/14" (Persian or Latin digits, / or -). @return array{0:int,1:int,2:int}|null */
    public static function parse(?string $value): ?array
    {
        $value = Digits::toEnglish(trim((string) $value));
        if (! preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $value, $m)) {
            return null;
        }
        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return self::isValid($jy, $jm, $jd) ? [$jy, $jm, $jd] : null;
    }

    /** @return array{leap:int,gy:int,march:int} */
    private static function jalCal(int $jy): array
    {
        $bl = count(self::BREAKS);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = self::BREAKS[0];
        $jump = 0;

        if ($jy < $jp || $jy >= self::BREAKS[$bl - 1]) {
            throw new InvalidArgumentException("Invalid Jalali year {$jy}");
        }

        for ($i = 1; $i < $bl; $i++) {
            $jm = self::BREAKS[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leapJ += intdiv($jump, 33) * 8 + intdiv($jump % 33, 4);
            $jp = $jm;
        }

        $n = $jy - $jp;
        $leapJ += intdiv($n, 33) * 8 + intdiv(($n % 33) + 3, 4);
        if ($jump % 33 === 4 && $jump - $n === 4) {
            $leapJ++;
        }

        $leapG = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;

        if ($jump - $n < 6) {
            $n = $n - $jump + intdiv($jump + 4, 33) * 33;
        }
        $leap = ((($n + 1) % 33) - 1) % 4;
        if ($leap === -1) {
            $leap = 4;
        }

        return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
    }

    private static function j2d(int $jy, int $jm, int $jd): int
    {
        $r = self::jalCal($jy);

        return self::g2d($r['gy'], 3, $r['march']) + ($jm - 1) * 31 - intdiv($jm, 7) * ($jm - 7) + $jd - 1;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function d2j(int $jdn): array
    {
        [$gy] = self::d2g($jdn);
        $jy = $gy - 621;
        $r = self::jalCal($jy);
        $jdn1f = self::g2d($gy, 3, $r['march']);
        $k = $jdn - $jdn1f;

        if ($k >= 0) {
            if ($k <= 185) {
                return [$jy, 1 + intdiv($k, 31), ($k % 31) + 1];
            }
            $k -= 186;
        } else {
            $jy--;
            $k += 179;
            if ($r['leap'] === 1) {
                $k++;
            }
        }

        return [$jy, 7 + intdiv($k, 30), ($k % 30) + 1];
    }

    private static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = intdiv(($gy + intdiv($gm - 8, 6) + 100100) * 1461, 4)
            + intdiv(153 * (($gm + 9) % 12) + 2, 5)
            + $gd - 34840408;

        return $d - intdiv(intdiv($gy + 100100 + intdiv($gm - 8, 6), 100) * 3, 4) + 752;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j += intdiv(intdiv(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = intdiv($j % 1461, 4) * 5 + 308;
        $gd = intdiv($i % 153, 5) + 1;
        $gm = (intdiv($i, 153) % 12) + 1;
        $gy = intdiv($j, 1461) - 100100 + intdiv(8 - $gm, 6);

        return [$gy, $gm, $gd];
    }
}
