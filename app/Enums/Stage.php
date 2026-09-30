<?php

namespace App\Enums;

/**
 * Approval stage of one project inside a monthly sheet.
 * Each approval locks editing for the approver and every role below it.
 */
enum Stage: int
{
    case Draft = 0;
    case ProjectApproved = 1;
    case HrApproved = 2;
    case Final = 3;

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'در حال تکمیل',
            self::ProjectApproved => 'تایید مدیر پروژه',
            self::HrApproved => 'تایید منابع انسانی',
            self::Final => 'تایید مالی · نهایی',
        };
    }

    /** The action label that moves a list INTO this stage. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Draft => 'بازگشایی',
            self::ProjectApproved => 'تایید پروژه',
            self::HrApproved => 'تایید منابع انسانی',
            self::Final => 'تایید مالی',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::ProjectApproved => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::HrApproved => 'bg-indigo-50 text-indigo-800 ring-indigo-200',
            self::Final => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        };
    }
}
