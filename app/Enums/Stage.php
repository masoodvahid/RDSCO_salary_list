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
            self::HrApproved => 'bg-violet-50 text-violet-800 ring-violet-200',
            self::Final => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        };
    }

    /** Solid stage color for dots, bars and progress segments. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-stage-draft',
            self::ProjectApproved => 'bg-stage-project',
            self::HrApproved => 'bg-stage-hr',
            self::Final => 'bg-stage-final',
        };
    }

    /** Readable text color in the stage hue (for numbers and labels on white). */
    public function textClass(): string
    {
        return match ($this) {
            self::Draft => 'text-amber-700',
            self::ProjectApproved => 'text-sky-700',
            self::HrApproved => 'text-violet-700',
            self::Final => 'text-emerald-700',
        };
    }
}
