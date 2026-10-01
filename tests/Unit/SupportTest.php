<?php

namespace Tests\Unit;

use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Mobile;
use App\Support\NationalCode;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_jalali_conversion_known_dates(): void
    {
        $this->assertSame([1405, 7, 8], Jalali::fromGregorian(2026, 9, 30));
        $this->assertSame([1403, 1, 1], Jalali::fromGregorian(2024, 3, 20));
        $this->assertSame([1403, 12, 30], Jalali::fromGregorian(2025, 3, 20));
        $this->assertSame([2026, 10, 6], Jalali::toGregorian(1405, 7, 14));
        $this->assertTrue(Jalali::isLeap(1403));
        $this->assertFalse(Jalali::isLeap(1404));
        $this->assertSame(29, Jalali::monthLength(1404, 12));
    }

    public function test_jalali_round_trip_over_several_years(): void
    {
        $date = new \DateTimeImmutable('2023-01-01');
        for ($i = 0; $i < 1500; $i++) {
            $d = $date->modify("+{$i} days");
            [$jy, $jm, $jd] = Jalali::fromGregorian((int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j'));
            $this->assertSame([(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')], Jalali::toGregorian($jy, $jm, $jd));
        }
    }

    public function test_jalali_parse(): void
    {
        $this->assertSame([1405, 7, 14], Jalali::parse('۱۴۰۵/۰۷/۱۴'));
        $this->assertSame([1405, 7, 14], Jalali::parse('1405-7-14'));
        $this->assertNull(Jalali::parse('1405/13/01'));
        $this->assertNull(Jalali::parse('1404/12/30'));
    }

    public function test_numbers_are_normalized(): void
    {
        $this->assertSame('185000000', Digits::normalizeNumber('۱۸۵٬۰۰۰٬۰۰۰'));
        $this->assertSame('1200.5', Digits::normalizeNumber('1,200.50'));
        $this->assertSame('-12', Digits::normalizeNumber('-012'));
        $this->assertNull(Digits::normalizeNumber('  '));
        $this->assertFalse(Digits::normalizeNumber('12a'));
        $this->assertSame('185,000,000', Digits::group('185000000'));
        $this->assertSame('۱٬۵۰۰٬۰۰۰', Digits::money(1500000));
        $this->assertSame('300000000.5', Digits::add('150000000.25', '150000000.25'));
    }

    public function test_numbers_compare_exactly(): void
    {
        $this->assertSame(1, Digits::compare('10', '9'));
        $this->assertSame(-1, Digits::compare('-5', '3'));
        $this->assertSame(1, Digits::compare('-5', '-10'));
        $this->assertSame(0, Digits::compare('1.50', '1.5'));
        $this->assertSame(-1, Digits::compare('1.05', '1.5'));
        $this->assertSame(0, Digits::compare('۱۲٬۵۰۰', '12500'));
        $this->assertSame(-1, Digits::compare('31', '31.0001'));
        $this->assertSame(-1, Digits::compare('123456789012345678901234567890', '123456789012345678901234567891'));
    }

    public function test_national_code_checksum(): void
    {
        $this->assertTrue(NationalCode::isValid('0499370899'));
        $this->assertTrue(NationalCode::isValid(NationalCode::fromNineDigits('001245876')));
        $this->assertFalse(NationalCode::isValid('0499370898'));
        $this->assertFalse(NationalCode::isValid('1111111111'));
        $this->assertSame('0499370899', NationalCode::normalize('499370899'));
    }

    public function test_mobile_normalization(): void
    {
        $this->assertSame('09121234567', Mobile::normalize('+98 912 123 4567'));
        $this->assertSame('09121234567', Mobile::normalize('۰۹۱۲۱۲۳۴۵۶۷'));
        $this->assertTrue(Mobile::isValid('09121234567'));
        $this->assertFalse(Mobile::isValid('0912123456'));
        $this->assertSame('0912•••4567', Mobile::mask('09121234567'));
    }
}
