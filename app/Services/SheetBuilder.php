<?php

namespace App\Services;

use App\Enums\ColumnType;
use App\Enums\ReviewStatus;
use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SheetBuilder
{
    /**
     * Payroll columns a new (not copied) sheet starts with. Empty on purpose: every sheet has the
     * personnel fields (name, last name, national code, personnel code, project) built in, and the
     * payroll columns come from the Excel import or are added by hand. [title, type, locked]
     */
    public const DEFAULT_COLUMNS = [];

    public function __construct(private readonly ChangeLogger $log) {}

    /** End of day N (default 14) of the following Jalali month. */
    public function defaultDeadline(int $jy, int $jm): CarbonImmutable
    {
        [$ny, $nm] = Jalali::nextMonth($jy, $jm);

        return Jalali::toCarbon($ny, $nm, (int) config('tuka.deadline_day', 14))->endOfDay();
    }

    /**
     * Creates the sheet of a Jalali month. When copying, projects, columns and personnel are copied;
     * values of locked (manager-owned) columns are always copied, other values only when $copyAllValues.
     */
    public function create(User $user, int $jy, int $jm, ?Sheet $copyFrom = null, bool $copyAllValues = false, ?CarbonInterface $deadline = null): Sheet
    {
        if (! Jalali::isValid($jy, $jm, 1)) {
            throw ValidationException::withMessages(['month' => 'ماه انتخاب‌شده معتبر نیست.']);
        }
        if (Sheet::where('jalali_year', $jy)->where('jalali_month', $jm)->exists()) {
            throw ValidationException::withMessages(['month' => 'لیست حقوق این ماه قبلاً ساخته شده است.']);
        }

        return DB::transaction(function () use ($user, $jy, $jm, $copyFrom, $copyAllValues, $deadline) {
            $sheet = Sheet::create([
                'jalali_year' => $jy,
                'jalali_month' => $jm,
                'deadline_at' => $deadline ?? $this->defaultDeadline($jy, $jm),
                'created_by' => $user->id,
            ]);

            if ($copyFrom) {
                $this->copyFrom($copyFrom, $sheet, $copyAllValues);
            } else {
                foreach (Project::where('is_active', true)->orderBy('name')->pluck('id') as $projectId) {
                    SheetProject::create(['sheet_id' => $sheet->id, 'project_id' => $projectId]);
                }
                foreach (self::DEFAULT_COLUMNS as $i => [$title, $type, $locked]) {
                    SheetColumn::create([
                        'sheet_id' => $sheet->id,
                        'title' => $title,
                        'type' => $type,
                        'is_locked' => $locked,
                        'position' => $i + 1,
                    ]);
                }
            }

            $this->log->record($sheet->id, $user, 'sheet.create', meta: [
                'copied_from' => $copyFrom?->id,
                'copy_all_values' => $copyAllValues,
            ]);

            return $sheet;
        });
    }

    private function copyFrom(Sheet $from, Sheet $to, bool $copyAllValues): void
    {
        $activeProjectIds = Project::query()
            ->whereIn('id', SheetProject::where('sheet_id', $from->id)->pluck('project_id'))
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($activeProjectIds as $projectId) {
            SheetProject::create(['sheet_id' => $to->id, 'project_id' => $projectId]);
        }

        $columnMap = [];
        $copyColumn = [];
        foreach (SheetColumn::where('sheet_id', $from->id)->orderBy('position')->orderBy('id')->get() as $column) {
            $new = SheetColumn::create([
                'sheet_id' => $to->id,
                'title' => $column->title,
                'type' => $column->type instanceof ColumnType ? $column->type->value : $column->type,
                'min_value' => $column->min_value,
                'max_value' => $column->max_value,
                'is_locked' => $column->is_locked,
                'position' => $column->position,
            ]);
            $columnMap[$column->id] = $new->id;
            $copyColumn[$column->id] = $copyAllValues || $column->is_locked;
        }

        $rowMap = [];
        foreach (SheetRow::where('sheet_id', $from->id)->orderBy('position')->orderBy('id')->cursor() as $row) {
            $projectId = in_array((int) $row->project_id, $activeProjectIds, true) ? $row->project_id : null;
            $new = SheetRow::create([
                'sheet_id' => $to->id,
                'project_id' => $projectId,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'personnel_code' => $row->personnel_code,
                'national_code' => $row->national_code,
                'position' => $row->position,
                'review_status' => ReviewStatus::Pending,
            ]);
            $rowMap[$row->id] = $new->id;
        }

        $columnsToCopy = array_keys(array_filter($copyColumn));
        if ($rowMap === [] || $columnsToCopy === []) {
            return;
        }

        $now = now();
        SheetCell::query()
            ->whereIn('row_id', array_keys($rowMap))
            ->whereIn('column_id', $columnsToCopy)
            ->whereNotNull('value')
            ->orderBy('id')
            ->chunk(1000, function ($cells) use ($rowMap, $columnMap, $now) {
                $insert = [];
                foreach ($cells as $cell) {
                    $insert[] = [
                        'row_id' => $rowMap[$cell->row_id],
                        'column_id' => $columnMap[$cell->column_id],
                        'value' => $cell->value,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                SheetCell::insert($insert);
            });
    }
}
