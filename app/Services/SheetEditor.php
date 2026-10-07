<?php

namespace App\Services;

use App\Enums\ColumnType;
use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\ListComment;
use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Support\Digits;
use App\Support\NationalCode;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All writes to a sheet's cells, rows and columns go through here.
 *
 * A change to a list that is already locked (CEO-approved or final; only the manager may still change it) is
 * logged with afterApproval() meta, so it shows as made after approval.
 */
final class SheetEditor
{
    private const MAX_BATCH = 1000;

    public function __construct(
        private readonly SheetAccess $access,
        private readonly ChangeLogger $log,
    ) {}

    /**
     * Saves a batch of cell edits from the grid with optimistic versioning.
     *
     * A change is either a payroll cell {row, column, value, version} or, for managers,
     * a personnel field {row, field, value}.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{saved: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, errors: list<array<string, mixed>>, denied: list<array<string, mixed>>}
     */
    public function saveCells(User $user, Sheet $sheet, array $changes): array
    {
        $result = ['saved' => [], 'conflicts' => [], 'errors' => [], 'denied' => []];
        $changes = array_slice(array_values(array_filter($changes, 'is_array')), 0, self::MAX_BATCH);
        if ($changes === []) {
            return $result;
        }

        $rowIds = array_values(array_unique(array_map(fn ($c) => (int) ($c['row'] ?? 0), $changes)));
        $rows = SheetRow::where('sheet_id', $sheet->id)->whereIn('id', $rowIds)->get()->keyBy('id');
        $columns = SheetColumn::where('sheet_id', $sheet->id)->get()->keyBy('id');

        DB::transaction(function () use ($user, $sheet, $changes, $rows, $columns, &$result) {
            // Lock the lists this batch touches: an approval waits for these edits (and signs them),
            // or, if it came first, the stage read here is already the approved one.
            $sheetProjects = SheetProject::where('sheet_id', $sheet->id)
                ->whereIn('project_id', $rows->pluck('project_id')->filter()->unique()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('project_id');

            foreach ($changes as $change) {
                $key = [
                    'row' => (int) ($change['row'] ?? 0),
                    'column' => isset($change['column']) && $change['column'] !== null ? (int) $change['column'] : null,
                    'field' => isset($change['field']) && $change['field'] !== '' ? (string) $change['field'] : null,
                ];

                /** @var SheetRow|null $row */
                $row = $rows->get($key['row']);
                if (! $row) {
                    $result['denied'][] = $key + ['message' => 'این ردیف پیدا نشد.'];

                    continue;
                }
                $sheetProject = $row->project_id ? $sheetProjects->get($row->project_id) : null;

                if ($key['field'] !== null) {
                    $this->saveIdentityField($user, $sheet, $row, $sheetProject, $key, $change['value'] ?? null, $result);

                    continue;
                }

                /** @var SheetColumn|null $column */
                $column = $key['column'] !== null ? $columns->get($key['column']) : null;
                if (! $column) {
                    $result['denied'][] = $key + ['message' => 'این ستون پیدا نشد.'];

                    continue;
                }
                if (! $this->access->canEditCell($user, $sheet, $row, $column, $sheetProject)) {
                    $result['denied'][] = $key + ['message' => 'اجازه ویرایش این خانه را ندارید.'];

                    continue;
                }

                $raw = isset($change['value']) ? trim((string) $change['value']) : null;
                if ($column->isNumber()) {
                    $value = Digits::normalizeNumber($raw);
                    if ($value === false) {
                        $result['errors'][] = $key + ['message' => 'در این ستون فقط عدد وارد کنید.'];

                        continue;
                    }
                    if (($rangeError = $column->rangeError($value)) !== null) {
                        $result['errors'][] = $key + ['message' => $rangeError];

                        continue;
                    }
                } else {
                    $value = ($raw === null || $raw === '') ? null : mb_substr($raw, 0, 500);
                }

                $cell = SheetCell::where('row_id', $row->id)->where('column_id', $column->id)->lockForUpdate()->first();
                $currentVersion = $cell?->version ?? 0;
                $clientVersion = isset($change['version']) && $change['version'] !== null ? (int) $change['version'] : null;

                if ($cell?->value === $value || (! $cell && $value === null)) {
                    $result['saved'][] = $key + ['value' => $value, 'version' => $currentVersion];

                    continue;
                }

                // Whole numbers only; a value with a fraction saved before this rule may stay as it is (above).
                if ($column->isNumber() && Digits::normalizeInteger($value) === false) {
                    $result['errors'][] = $key + ['message' => ColumnType::INTEGER_ONLY];

                    continue;
                }

                if ($clientVersion !== null && $clientVersion !== $currentVersion) {
                    $result['conflicts'][] = $key + [
                        'value' => $cell?->value,
                        'version' => $currentVersion,
                        'message' => 'این خانه هم‌زمان توسط '.($cell?->editor?->name ?? 'کاربر دیگری').' تغییر کرده است.',
                    ];

                    continue;
                }

                $old = $cell?->value;
                try {
                    if ($cell) {
                        $cell->update(['value' => $value, 'version' => $currentVersion + 1, 'updated_by' => $user->id]);
                    } else {
                        $cell = SheetCell::create([
                            'row_id' => $row->id,
                            'column_id' => $column->id,
                            'value' => $value,
                            'version' => 1,
                            'updated_by' => $user->id,
                        ]);
                    }
                } catch (UniqueConstraintViolationException) {
                    $result['conflicts'][] = $key + ['value' => null, 'version' => 0, 'message' => 'این خانه هم‌زمان توسط کاربر دیگری ثبت شد؛ دوباره وارد کنید.'];

                    continue;
                }

                $this->log->record($sheet->id, $user, 'cell.update', $row->id, $column->id, $old, $value, $this->afterApproval([$sheetProject]));
                $this->resetRejectedReview($user, $sheet, $row);

                $result['saved'][] = $key + ['value' => $value, 'version' => $cell->version];
            }
        });

        return $result;
    }

    /**
     * @param  array{row:int, column:int|null, field:string|null}  $key
     * @param  array<string, list<array<string, mixed>>>  $result
     */
    private function saveIdentityField(User $user, Sheet $sheet, SheetRow $row, ?SheetProject $sheetProject, array $key, mixed $value, array &$result): void
    {
        $field = (string) $key['field'];
        if (! in_array($field, SheetRow::IDENTITY_FIELDS, true) || ! $this->access->canEditIdentity($user, $sheetProject)) {
            $result['denied'][] = $key + ['message' => 'فقط مدیر می‌تواند اطلاعات پرسنلی را تغییر دهد.'];

            return;
        }

        $value = trim((string) $value);
        $error = null;

        switch ($field) {
            case 'first_name':
            case 'last_name':
                if ($value === '' || mb_strlen($value) > 80) {
                    $error = 'نام و نام خانوادگی الزامی است.';
                }
                break;
            case 'personnel_code':
                $value = Digits::toEnglish($value);
                if (mb_strlen($value) > 20) {
                    $error = 'کد پرسنلی حداکثر ۲۰ کاراکتر است.';
                }
                break;
            case 'national_code':
                $normalized = NationalCode::normalize($value);
                if (! NationalCode::isValid($normalized)) {
                    $error = 'کد ملی معتبر نیست.';
                } elseif (SheetRow::where('sheet_id', $sheet->id)->where('national_code', $normalized)->whereKeyNot($row->id)->exists()) {
                    $error = 'این کد ملی در لیست حقوق این ماه تکراری است.';
                }
                $value = (string) $normalized;
                break;
        }

        if ($error !== null) {
            $result['errors'][] = $key + ['message' => $error];

            return;
        }

        $stored = $field === 'personnel_code' && $value === '' ? null : $value;
        $old = $row->{$field};
        if ($old !== $stored) {
            $row->update([$field => $stored]);
            $this->log->record($sheet->id, $user, 'row.update', $row->id, null, $old, $stored, ['field' => $field] + ($this->afterApproval([$sheetProject]) ?? []));
        }

        $result['saved'][] = $key + ['value' => $stored, 'version' => 0];
    }

    /** A value change on a rejected record sends it back for review (also used by the Excel import). */
    public function resetRejectedReview(User $user, Sheet $sheet, SheetRow $row): void
    {
        if ($row->review_status !== ReviewStatus::Rejected) {
            return;
        }

        $row->update(['review_status' => ReviewStatus::Pending, 'reviewed_by' => null, 'reviewed_at' => null]);
        $this->log->record($sheet->id, $user, 'row.review.reset', $row->id);
    }

    /** @param array{first_name?:string,last_name?:string,personnel_code?:string|null,national_code?:string,project_id?:int|string|null} $data */
    public function addRow(User $user, Sheet $sheet, array $data): SheetRow
    {
        $this->authorizeManage($user);

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $personnel = trim(Digits::toEnglish((string) ($data['personnel_code'] ?? '')));
        $national = NationalCode::normalize((string) ($data['national_code'] ?? ''));
        $projectId = filled($data['project_id'] ?? null) ? (int) $data['project_id'] : null;

        $errors = [];
        if ($first === '' || mb_strlen($first) > 80) {
            $errors['newRow.first_name'] = 'نام الزامی است.';
        }
        if ($last === '' || mb_strlen($last) > 80) {
            $errors['newRow.last_name'] = 'نام خانوادگی الزامی است.';
        }
        if (mb_strlen($personnel) > 20) {
            $errors['newRow.personnel_code'] = 'کد پرسنلی حداکثر ۲۰ کاراکتر است.';
        }
        if (! NationalCode::isValid($national)) {
            $errors['newRow.national_code'] = 'کد ملی معتبر نیست.';
        } elseif (SheetRow::where('sheet_id', $sheet->id)->where('national_code', $national)->exists()) {
            $errors['newRow.national_code'] = 'این کد ملی در لیست حقوق این ماه وجود دارد.';
        }
        $sheetProject = $projectId !== null ? SheetProject::where('sheet_id', $sheet->id)->where('project_id', $projectId)->first() : null;
        if ($projectId !== null && ! $sheetProject) {
            $errors['newRow.project_id'] = 'این پروژه در پروژه‌های این ماه نیست.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $sheet, $first, $last, $personnel, $national, $projectId, $sheetProject) {
            $row = SheetRow::create([
                'sheet_id' => $sheet->id,
                'project_id' => $projectId,
                'first_name' => $first,
                'last_name' => $last,
                'personnel_code' => $personnel === '' ? null : $personnel,
                'national_code' => $national,
                'position' => ((int) SheetRow::where('sheet_id', $sheet->id)->max('position')) + 1,
                'review_status' => ReviewStatus::Pending,
            ]);
            $this->log->record($sheet->id, $user, 'row.create', $row->id, null, null, $row->fullName(), $this->afterApproval([$sheetProject]));

            return $row;
        });
    }

    public function deleteRow(User $user, SheetRow $row): void
    {
        $this->authorizeManage($user);
        $meta = $this->afterApproval($this->sheetProjectsOf($row->sheet_id, [$row->project_id]));

        DB::transaction(function () use ($user, $row, $meta) {
            $this->log->record($row->sheet_id, $user, 'row.delete', $row->id, null, $row->fullName().' · '.$row->national_code, null, $meta);
            $row->delete();
        });
    }

    /**
     * Deletes several rows at once (manager's multi-select).
     *
     * @param  list<int|string>  $rowIds
     * @return int number of deleted rows
     */
    public function deleteRows(User $user, Sheet $sheet, array $rowIds): int
    {
        $this->authorizeManage($user);
        $rows = $this->selectedRows($sheet, $rowIds);
        $sheetProjects = $this->sheetProjectsOf($sheet->id, $rows->pluck('project_id')->all());

        DB::transaction(function () use ($user, $sheet, $rows, $sheetProjects) {
            foreach ($rows as $row) {
                $meta = $this->afterApproval([$sheetProjects->get($row->project_id)]);
                $this->log->record($sheet->id, $user, 'row.delete', $row->id, null, $row->fullName().' · '.$row->national_code, null, $meta);
            }
            SheetRow::whereIn('id', $rows->pluck('id'))->delete(); // cells and notes cascade
        });

        return $rows->count();
    }

    /**
     * Assigns one project (or none) to several rows.
     *
     * @param  list<int|string>  $rowIds
     * @return int number of rows whose project changed
     */
    public function setRowsProject(User $user, Sheet $sheet, array $rowIds, ?int $projectId): int
    {
        $this->authorizeManage($user);

        if ($projectId !== null && ! SheetProject::where('sheet_id', $sheet->id)->where('project_id', $projectId)->exists()) {
            throw ValidationException::withMessages(['rows' => 'این پروژه در پروژه‌های این ماه نیست.']);
        }
        $rows = $this->selectedRows($sheet, $rowIds);
        $sheetProjects = $this->sheetProjectsOf($sheet->id, [...$rows->pluck('project_id')->all(), $projectId]);

        $names = Project::pluck('name', 'id');
        $changed = 0;
        DB::transaction(function () use ($user, $sheet, $rows, $projectId, $names, $sheetProjects, &$changed) {
            foreach ($rows as $row) {
                if ((int) $row->project_id === (int) $projectId) {
                    continue;
                }
                $old = $row->project_id ? $names->get($row->project_id) : null;
                $meta = $this->afterApproval([$sheetProjects->get($row->project_id), $sheetProjects->get($projectId)]);
                $row->update(['project_id' => $projectId]);
                $this->log->record($sheet->id, $user, 'row.project', $row->id, null, $old, $projectId ? $names->get($projectId) : null, $meta);
                $changed++;
            }
        });

        return $changed;
    }

    /** @param list<int|string> $rowIds */
    private function selectedRows(Sheet $sheet, array $rowIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $rowIds))));
        if ($ids === []) {
            throw ValidationException::withMessages(['rows' => 'ردیفی انتخاب نشده است.']);
        }
        if (count($ids) > 5000) {
            throw ValidationException::withMessages(['rows' => 'حداکثر ۵۰۰۰ ردیف را می‌توان یک‌جا تغییر داد.']);
        }

        return SheetRow::where('sheet_id', $sheet->id)->whereIn('id', $ids)->get();
    }

    /**
     * Log meta for a change to lists that are already locked (CEO-approved or final): ['after_approval' => [project
     * id => stage]], so the change log, the member's activity and the list's approval timeline show it as made
     * after approval. Null when none of them is locked.
     *
     * @param  iterable<SheetProject|null>  $sheetProjects
     * @return array{after_approval: array<int, int>}|null
     */
    public function afterApproval(iterable $sheetProjects): ?array
    {
        $locked = [];
        foreach ($sheetProjects as $sheetProject) {
            if ($sheetProject?->stage->isLocked()) {
                $locked[(int) $sheetProject->project_id] = $sheetProject->stage->value;
            }
        }

        return $locked === [] ? null : ['after_approval' => $locked];
    }

    /**
     * @param  array<int|string|null>  $projectIds
     * @return Collection<int, SheetProject> keyed by project id
     */
    private function sheetProjectsOf(int $sheetId, array $projectIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds))));

        return $ids === [] ? collect() : SheetProject::where('sheet_id', $sheetId)->whereIn('project_id', $ids)->get()->keyBy('project_id');
    }

    public function setRowProject(User $user, SheetRow $row, ?int $projectId): void
    {
        $this->authorizeManage($user);

        if ($projectId !== null && ! SheetProject::where('sheet_id', $row->sheet_id)->where('project_id', $projectId)->exists()) {
            throw ValidationException::withMessages(['project' => 'این پروژه در پروژه‌های این ماه نیست.']);
        }
        if ((int) $row->project_id === (int) $projectId) {
            return;
        }

        $meta = $this->afterApproval($this->sheetProjectsOf($row->sheet_id, [$row->project_id, $projectId]));
        $old = $row->project_id ? Project::find($row->project_id)?->name : null;
        $row->update(['project_id' => $projectId]);
        $new = $projectId ? Project::find($projectId)?->name : null;
        $this->log->record($row->sheet_id, $user, 'row.project', $row->id, null, $old, $new, $meta);
    }

    /** $min / $max: optional bounds for number columns (Persian digits and separators accepted); empty = no limit. */
    public function addColumn(User $user, Sheet $sheet, string $title, string $type, bool $locked, ?string $min = null, ?string $max = null): SheetColumn
    {
        $this->authorizeManage($user);
        [$title, $type, $min, $max] = $this->validateColumn($sheet, $title, $type, $min, $max);

        $column = SheetColumn::create([
            'sheet_id' => $sheet->id,
            'title' => $title,
            'type' => $type,
            'min_value' => $min,
            'max_value' => $max,
            'is_locked' => $locked,
            'position' => ((int) SheetColumn::where('sheet_id', $sheet->id)->max('position')) + 1,
        ]);
        // A new column is part of every list of the month, the locked ones included.
        $this->log->record($sheet->id, $user, 'column.create', null, $column->id, null, $title,
            ['type' => $type, 'locked' => $locked, 'min' => $min, 'max' => $max] + ($this->afterApproval(SheetProject::where('sheet_id', $sheet->id)->get()) ?? []));

        return $column;
    }

    /**
     * A new range is not applied retroactively: existing values stay, new edits must respect it.
     *
     * @return int how many existing values fall outside the column's range after the update
     */
    public function updateColumn(User $user, SheetColumn $column, string $title, string $type, bool $locked, ?string $min = null, ?string $max = null): int
    {
        $this->authorizeManage($user);
        $sheet = $column->sheet;
        [$title, $type, $min, $max] = $this->validateColumn($sheet, $title, $type, $min, $max, $column);

        if ($type === ColumnType::Number->value && $column->type !== ColumnType::Number) {
            $values = SheetCell::where('column_id', $column->id)->whereNotNull('value')->pluck('value');
            if ($values->contains(fn ($value) => Digits::normalizeNumber($value) === false)) {
                throw ValidationException::withMessages(['columnType' => 'این ستون مقدار غیرعددی دارد و نمی‌تواند عددی شود.']);
            }
            if ($values->contains(fn ($value) => Digits::normalizeInteger($value) === false)) {
                throw ValidationException::withMessages(['columnType' => 'این ستون مقدار اعشاری دارد و نمی‌تواند از نوع عدد صحیح شود.']);
            }
        }

        $before = ['title' => $column->title, 'type' => $column->type->value, 'locked' => $column->is_locked, 'min' => $column->min_value, 'max' => $column->max_value];
        $after = ['title' => $title, 'type' => $type, 'locked' => $locked, 'min' => $min, 'max' => $max];
        $column->update(['title' => $title, 'type' => $type, 'is_locked' => $locked, 'min_value' => $min, 'max_value' => $max]);
        $this->log->record($sheet->id, $user, 'column.update', null, $column->id, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE),
            $this->afterApproval(SheetProject::where('sheet_id', $sheet->id)->get()));

        if (! $column->hasRange()) {
            return 0;
        }

        return SheetCell::where('column_id', $column->id)->whereNotNull('value')->pluck('value')
            ->filter(fn ($value) => $column->isOutOfRange($value))
            ->count();
    }

    public function deleteColumn(User $user, SheetColumn $column): void
    {
        $this->authorizeManage($user);

        if (SheetProject::where('sheet_id', $column->sheet_id)->where('stage', '>', Stage::Draft->value)->exists()) {
            throw ValidationException::withMessages(['column' => 'بعد از اولین تایید، حذف ستون ممکن نیست. می‌توانید آن را قفل کنید.']);
        }

        DB::transaction(function () use ($user, $column) {
            $this->log->record($column->sheet_id, $user, 'column.delete', null, $column->id, $column->title);
            $column->delete();
        });
    }

    /** Moves a column one step toward the start (-1) or end (+1). */
    public function moveColumn(User $user, SheetColumn $column, int $direction): void
    {
        $this->authorizeManage($user);

        $columns = SheetColumn::where('sheet_id', $column->sheet_id)->orderBy('position')->orderBy('id')->get()->values();
        $index = $columns->search(fn ($c) => $c->id === $column->id);
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($index === false || $target < 0 || $target >= $columns->count()) {
            return;
        }

        DB::transaction(function () use ($columns, $index, $target) {
            $ordered = $columns->all();
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $i => $col) {
                if ($col->position !== $i + 1) {
                    $col->update(['position' => $i + 1]);
                }
            }
        });
    }

    /**
     * Saves a full column order from drag & drop. Order is not part of the signed data
     * (the approval hash sorts columns by id), so it can change at any stage.
     *
     * @param  list<int|string>  $columnIds  every column of the sheet, in the new order
     */
    public function reorderColumns(User $user, Sheet $sheet, array $columnIds): void
    {
        $this->authorizeManage($user);

        $ids = array_values(array_map('intval', $columnIds));
        $columns = SheetColumn::where('sheet_id', $sheet->id)->get()->keyBy('id');
        $known = $columns->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = $ids;
        sort($given);
        if ($given !== $known) {
            throw ValidationException::withMessages(['column' => 'ترتیب ستون‌ها با ستون‌های فعلی نمی‌خواند؛ صفحه را تازه کنید و دوباره امتحان کنید.']);
        }

        DB::transaction(function () use ($user, $sheet, $ids, $columns) {
            foreach ($ids as $i => $id) {
                $column = $columns->get($id);
                if ($column->position !== $i + 1) {
                    $column->update(['position' => $i + 1]);
                }
            }
            $this->log->record($sheet->id, $user, 'column.reorder', meta: ['order' => $ids]);
        });
    }

    /** @param list<int|string> $projectIds */
    public function setMonthProjects(User $user, Sheet $sheet, array $projectIds): void
    {
        $this->authorizeManage($user);

        $wanted = Project::whereIn('id', array_map('intval', $projectIds))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $current = SheetProject::where('sheet_id', $sheet->id)->get()->keyBy('project_id');

        $removing = $current->keys()->map(fn ($id) => (int) $id)->diff($wanted);
        foreach ($removing as $projectId) {
            $sheetProject = $current->get($projectId);
            if ($sheetProject->stage !== Stage::Draft
                || SheetRow::where('sheet_id', $sheet->id)->where('project_id', $projectId)->exists()
                || ListComment::where('sheet_project_id', $sheetProject->id)->exists()) {
                $name = Project::find($projectId)?->name;
                throw ValidationException::withMessages(['monthProjects' => "پروژه «{$name}» ردیف، تایید یا کامنت دارد و نمی‌تواند از این ماه حذف شود."]);
            }
        }

        DB::transaction(function () use ($user, $sheet, $wanted, $current, $removing) {
            foreach ($removing as $projectId) {
                $current->get($projectId)->delete();
            }
            foreach ($wanted as $projectId) {
                if (! $current->has($projectId)) {
                    SheetProject::create(['sheet_id' => $sheet->id, 'project_id' => $projectId]);
                }
            }
            $this->log->record($sheet->id, $user, 'sheet.projects', meta: ['projects' => $wanted]);
        });
    }

    public function setDeadline(User $user, Sheet $sheet, CarbonInterface $deadline): void
    {
        $this->authorizeManage($user);

        $old = $sheet->deadline_at?->toDateTimeString();
        $sheet->update(['deadline_at' => $deadline]);
        $this->log->record($sheet->id, $user, 'sheet.deadline', null, null, $old, $deadline->toDateTimeString());
    }

    /**
     * @param  SheetColumn|null  $current  the column being edited (null for a new one)
     * @return array{0:string,1:string,2:string|null,3:string|null}
     */
    private function validateColumn(Sheet $sheet, string $title, string $type, ?string $min, ?string $max, ?SheetColumn $current = null): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $errors = [];
        if ($title === '' || mb_strlen($title) > 80) {
            $errors['columnTitle'] = 'عنوان ستون الزامی است (حداکثر ۸۰ کاراکتر).';
        } elseif (SheetColumn::where('sheet_id', $sheet->id)->where('title', $title)->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            $errors['columnTitle'] = 'ستونی با این عنوان وجود دارد.';
        }
        if (ColumnType::tryFrom($type) === null) {
            $errors['columnType'] = 'نوع ستون معتبر نیست.';
        }

        // A range only makes sense for numbers; switching to text drops it. Number columns hold whole numbers,
        // but a bound with a fraction saved before that rule may stay while other settings of the column change.
        $bound = fn (?string $value, ?string $saved) => Digits::normalizeNumber($value) === $saved && $saved !== null ? $saved : Digits::normalizeInteger($value);
        $min = $type === ColumnType::Number->value ? $bound($min, $current?->min_value) : null;
        $max = $type === ColumnType::Number->value ? $bound($max, $current?->max_value) : null;
        if ($min === false || (is_string($min) && strlen($min) > 40)) {
            $errors['columnMin'] = 'حداقل را به شکل عدد صحیح (بدون اعشار) وارد کنید یا خالی بگذارید.';
        }
        if ($max === false || (is_string($max) && strlen($max) > 40)) {
            $errors['columnMax'] = 'حداکثر را به شکل عدد صحیح (بدون اعشار) وارد کنید یا خالی بگذارید.';
        }
        if (is_string($min) && is_string($max) && ! isset($errors['columnMin']) && ! isset($errors['columnMax']) && Digits::compare($min, $max) > 0) {
            $errors['columnMax'] = 'حداکثر نباید از حداقل کمتر باشد.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$title, $type, $min, $max];
    }

    private function authorizeManage(User $user): void
    {
        if (! $this->access->canManage($user)) {
            throw new AuthorizationException('این کار فقط برای مدیر مجاز است.');
        }
    }
}
