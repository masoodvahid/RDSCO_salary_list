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

    public function chipClass(): string
    {
        return match ($this) {
            self::Viewer => 'bg-slate-50 text-slate-700 ring-slate-200',
            self::Editor => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::Approver => 'bg-violet-50 text-violet-800 ring-violet-200',
            self::Manager => 'bg-accent-soft text-accent ring-indigo-200',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Viewer => 'دیدن لیست حقوق و گذاشتن یادداشت',
            self::Editor => 'ویرایش ستون‌های باز؛ بدون افزودن ردیف و تغییر ستون‌های قفل',
            self::Approver => 'امکانات ویرایشگر + تایید با کد پیامکی (روی یک پروژه: تایید پروژه؛ روی همه پروژه‌ها: تایید مالی)',
            self::Manager => 'همه عملیات: ردیف، ستون، قفل ستون، پروژه‌ها، اعضا و تایید منابع انسانی',
        };
    }
}
