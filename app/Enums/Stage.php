<?php

namespace App\Enums;

/**
 * Approval stage of one project inside a monthly sheet:
 * editor fills it → project manager → HR → CEO → finance (final).
 *
 * The stored values never change meaning for data already in the database except one, on purpose:
 * 3 was the final "finance" approval before the CEO step existed, and those approvals were given by
 * the CEO. So 3 is now the CEO's approval (kept, with its signatures) and finance approves at 4.
 */
enum Stage: int
{
    case Draft = 0;
    case ProjectApproved = 1;
    case HrApproved = 2;
    case CeoApproved = 3;
    case Final = 4;

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'در حال تکمیل',
            self::ProjectApproved => 'تایید مدیر پروژه',
            self::HrApproved => 'تایید منابع انسانی',
            self::CeoApproved => 'تایید مدیرعامل',
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
            self::CeoApproved => 'تایید مدیرعامل',
            self::Final => 'تایید مالی',
        };
    }

    /**
     * From the CEO's approval on, nobody edits the list any more (managers included) until a rejection
     * or a reopen brings it back. Before the CEO step existed this was the final stage, so lists that
     * were final stay locked.
     */
    public function isLocked(): bool
    {
        return $this->value >= self::CeoApproved->value;
    }

    /** @return list<int> stored values of the locked stages, for queries */
    public static function lockedValues(): array
    {
        return array_values(array_map(fn (self $stage) => $stage->value, array_filter(self::cases(), fn (self $stage) => $stage->isLocked())));
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::ProjectApproved => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::HrApproved => 'bg-violet-50 text-violet-800 ring-violet-200',
            self::CeoApproved => 'bg-pink-50 text-pink-800 ring-pink-200',
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
            self::CeoApproved => 'bg-stage-ceo',
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
            self::CeoApproved => 'text-pink-700',
            self::Final => 'text-emerald-700',
        };
    }
}
