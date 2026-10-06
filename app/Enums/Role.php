<?php

namespace App\Enums;

enum Role: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Approver = 'approver';
    case Finance = 'finance';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'مشاهده',
            self::Editor => 'ویرایشگر',
            self::Approver => 'تاییدکننده',
            self::Finance => 'مدیر مالی',
            self::Manager => 'مدیر',
        };
    }

    public function chipClass(): string
    {
        return match ($this) {
            self::Viewer => 'bg-slate-50 text-slate-700 ring-slate-200',
            self::Editor => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::Approver => 'bg-violet-50 text-violet-800 ring-violet-200',
            self::Finance => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Manager => 'bg-accent-soft text-accent ring-indigo-200',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Viewer => 'دیدن لیست حقوق و گذاشتن یادداشت',
            self::Editor => 'ویرایش ستون‌های باز، دستی یا با فایل اکسل؛ بدون افزودن ردیف و تغییر ستون‌های قفل',
            self::Approver => 'امکانات ویرایشگر + تایید با کد پیامکی (روی پروژه‌های مشخص: تایید مدیر پروژه؛ روی همه پروژه‌ها: تایید مدیرعامل، بعد از منابع انسانی)',
            self::Finance => 'آخرین تایید، روی همه پروژه‌ها: بعد از تایید مدیرعامل رکوردها را بررسی می‌کند و لیست را با کد پیامکی نهایی می‌کند',
            self::Manager => 'همه عملیات: ردیف، ستون، قفل ستون، پروژه‌ها، اعضا و تایید منابع انسانی',
        };
    }
}
