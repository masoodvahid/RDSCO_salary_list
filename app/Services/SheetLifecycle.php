<?php

namespace App\Services;

use App\Enums\Stage;
use App\Models\Approval;
use App\Models\Sheet;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Support\Digits;
use App\Support\Jalali;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deleting a monthly list and moving it to another month (managers).
 *
 *  - Delete: only an empty list (no personnel). With personnel: unapproved → remove the people first;
 *    approved → never.
 *  - Move: only to a month that has no list yet. If that month has a list, it must be deleted first,
 *    unless it is approved, which rules the move out. A list that is itself approved keeps its month.
 */
final class SheetLifecycle
{
    public function __construct(
        private readonly SheetAccess $access,
        private readonly ChangeLogger $log,
        private readonly UserActivity $activity,
        private readonly SheetBuilder $builder,
    ) {}

    /** Approved in any way: a project past Draft or a signature that still stands. */
    public function isApproved(Sheet $sheet): bool
    {
        $sheetProjects = SheetProject::where('sheet_id', $sheet->id);

        return (clone $sheetProjects)->where('stage', '!=', Stage::Draft->value)->exists()
            || Approval::whereIn('sheet_project_id', $sheetProjects->select('id'))->whereNull('revoked_at')->exists();
    }

    public function peopleCount(Sheet $sheet): int
    {
        return SheetRow::where('sheet_id', $sheet->id)->count();
    }

    /** Why the list cannot be deleted, or null when it can. */
    public function deleteBlocker(Sheet $sheet): ?string
    {
        $people = $this->peopleCount($sheet);
        if ($people === 0) {
            return null;
        }
        $count = Digits::toPersian($people);

        return $this->isApproved($sheet)
            ? "لیست حقوق {$sheet->title()} {$count} نفر دارد و تایید شده است؛ امکان حذف آن وجود ندارد."
            : "لیست حقوق {$sheet->title()} {$count} نفر دارد. اول نفرات را از لیست حذف کنید (در لیست حقوق: انتخاب همه‌ی ردیف‌ها، سپس «حذف ردیف‌ها») و بعد لیست را حذف کنید.";
    }

    public function delete(User $user, Sheet $sheet): void
    {
        $this->authorize($user);

        DB::transaction(function () use ($user, $sheet) {
            $sheet = Sheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if (($blocker = $this->deleteBlocker($sheet)) !== null) {
                throw ValidationException::withMessages(['sheet' => $blocker]);
            }

            // The list's own change log goes with it; the deletion is kept in the manager's activity.
            $this->activity->record($user, 'sheet.deleted', $user, [
                'sheet_id' => $sheet->id,
                'title' => $sheet->title(),
                'year' => $sheet->jalali_year,
                'month' => $sheet->jalali_month,
            ]);
            $sheet->delete(); // projects, columns, rows, cells, notes, approvals and comments cascade
        });
    }

    /** Why the list cannot move to that month, or null when it can. */
    public function moveBlocker(Sheet $sheet, int $year, int $month): ?string
    {
        if (! Jalali::isValid($year, $month, 1)) {
            return 'ماه انتخاب‌شده معتبر نیست.';
        }
        if ((int) $sheet->jalali_year === $year && (int) $sheet->jalali_month === $month) {
            return 'این لیست همین حالا برای همین ماه است.';
        }
        if (SheetProject::where('sheet_id', $sheet->id)->whereIn('stage', Stage::lockedValues())->exists()) {
            return "لیست حقوق {$sheet->title()} پروژه‌ی قفل‌شده (تایید مدیرعامل یا نهایی) دارد و ماه آن قابل تغییر نیست.";
        }
        if ($this->isApproved($sheet)) {
            return "لیست حقوق {$sheet->title()} تایید شده است؛ برای تغییر ماه، اول تاییدها را با «بازگشایی» برگردانید.";
        }

        $target = Sheet::where('jalali_year', $year)->where('jalali_month', $month)->first();
        if ($target) {
            return $this->isApproved($target)
                ? "لیست حقوق {$target->title()} تاییدیه دارد و امکان انتقال به آن وجود ندارد."
                : "لیست حقوق {$target->title()} از قبل وجود دارد. اگر به آن نیاز ندارید، اول آن را حذف کنید و دوباره ماه را تغییر دهید.";
        }

        return null;
    }

    /**
     * Moves the list (with everything in it) to another month. A deadline that was the default for the
     * old month becomes the default for the new one; a deadline set by hand is kept.
     *
     * @return bool whether the deadline changed
     */
    public function moveTo(User $user, Sheet $sheet, int $year, int $month): bool
    {
        $this->authorize($user);

        try {
            return DB::transaction(function () use ($user, $sheet, $year, $month) {
                $sheet = Sheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
                if (($blocker = $this->moveBlocker($sheet, $year, $month)) !== null) {
                    throw ValidationException::withMessages(['month' => $blocker]);
                }

                $oldTitle = $sheet->title();
                $wasDefault = $sheet->deadline_at?->timestamp === $this->builder->defaultDeadline((int) $sheet->jalali_year, (int) $sheet->jalali_month)->timestamp;
                $sheet->update([
                    'jalali_year' => $year,
                    'jalali_month' => $month,
                    'deadline_at' => $wasDefault ? $this->builder->defaultDeadline($year, $month) : $sheet->deadline_at,
                ]);
                $this->log->record($sheet->id, $user, 'sheet.month', null, null, $oldTitle, $sheet->title());

                return (bool) $wasDefault;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['month' => 'هم‌زمان لیست دیگری برای این ماه ساخته شد. صفحه را تازه کنید.']);
        }
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canManage($user)) {
            throw new AuthorizationException('فقط مدیر این کار را می‌کند.');
        }
    }
}
