<?php

namespace App\Enums;

enum Role: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Approver = 'approver';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'مشاهده',
            self::Editor => 'ویرایشگر',
            self::Approver => 'تاییدکننده',
            self::Manager => 'مدیر',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Viewer => 'دیدن شیت و گذاشتن یادداشت',
            self::Editor => 'ویرایش ستون‌های باز؛ بدون افزودن ردیف و تغییر ستون‌های قفل',
            self::Approver => 'امکانات ویرایشگر + تایید با کد پیامکی (روی یک پروژه: تایید پروژه؛ روی همه پروژه‌ها: تایید مالی)',
            self::Manager => 'همه عملیات: ردیف، ستون، قفل ستون، پروژه‌ها، اعضا و تایید منابع انسانی',
        };
    }
}
