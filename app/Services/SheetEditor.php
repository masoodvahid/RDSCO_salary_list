<?php

namespace App\Services;

use App\Enums\ColumnType;
use App\Enums\ReviewStatus;
use App\Enums\Stage;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All writes to a sheet's cells, rows and columns go through here.
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
        $sheetProjects = SheetProject::where('sheet_id', $sheet->id)->get()->keyBy('project_id');

        DB::transaction(function () use ($user, $sheet, $changes, $rows, $columns, $sheetProjects, &$result) {
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

                $this->log->record($sheet->id, $user, 'cell.update', $row->id, $column->id, $old, $value);
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
                    $error = 'این کد ملی در شیت تکراری است.';
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
            $this->log->record($sheet->id, $user, 'row.update', $row->id, null, $old, $stored, ['field' => $field]);
        }

        $result['saved'][] = $key + ['value' => $stored, 'version' => 0];
    }

    private function resetRejectedReview(User $user, Sheet $sheet, SheetRow $row): void
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
            $errors['newRow.national_code'] = 'این کد ملی در شیت این ماه وجود دارد.';
        }
        if ($projectId !== null) {
            $sheetProject = SheetProject::where('sheet_id', $sheet->id)->where('project_id', $projectId)->first();
            if (! $sheetProject) {
                $errors['newRow.project_id'] = 'این پروژه در پروژه‌های این ماه نیست.';
            } elseif ($sheetProject->stage === Stage::Final) {
                $errors['newRow.project_id'] = 'لیست این پروژه نهایی شده است.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $sheet, $first, $last, $personnel, $national, $projectId) {
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
            $this->log->record($sheet->id, $user, 'row.create', $row->id, null, null, $row->fullName());

            return $row;
        });
    }

    public function deleteRow(User $user, SheetRow $row): void
    {
        $this->authorizeManage($user);
        $this->assertProjectNotFinal($row->sheet_id, $row->project_id);

        DB::transaction(function () use ($user, $row) {
            $this->log->record($row->sheet_id, $user, 'row.delete', $row->id, null, $row->fullName().' · '.$row->national_code);
            $row->delete();
        });
    }

    public function setRowProject(User $user, SheetRow $row, ?int $projectId): void
    {
        $this->authorizeManage($user);

        if ($projectId !== null && ! SheetProject::where('sheet_id', $row->sheet_id)->where('project_id', $projectId)->exists()) {
            throw ValidationException::withMessages(['project' => 'این پروژه در پروژه‌های این ماه نیست.']);
        }
        $this->assertProjectNotFinal($row->sheet_id, $row->project_id);
        $this->assertProjectNotFinal($row->sheet_id, $projectId);

        if ((int) $row->project_id === (int) $projectId) {
            return;
        }

        $old = $row->project_id ? Project::find($row->project_id)?->name : null;
        $row->update(['project_id' => $projectId]);
        $new = $projectId ? Project::find($projectId)?->name : null;
        $this->log->record($row->sheet_id, $user, 'row.project', $row->id, null, $old, $new);
    }

    public function addColumn(User $user, Sheet $sheet, string $title, string $type, bool $locked): SheetColumn
    {
        $this->authorizeManage($user);
        $this->assertNoFinalProjects($sheet);
        [$title, $type] = $this->validateColumn($sheet, $title, $type);

        $column = SheetColumn::create([
            'sheet_id' => $sheet->id,
            'title' => $title,
            'type' => $type,
            'is_locked' => $locked,
            'position' => ((int) SheetColumn::where('sheet_id', $sheet->id)->max('position')) + 1,
        ]);
        $this->log->record($sheet->id, $user, 'column.create', null, $column->id, null, $title, ['type' => $type, 'locked' => $locked]);

        return $column;
    }

    public function updateColumn(User $user, SheetColumn $column, string $title, string $type, bool $locked): void
    {
        $this->authorizeManage($user);
        $sheet = $column->sheet;
        [$title, $type] = $this->validateColumn($sheet, $title, $type, $column->id);

        $changesSignedData = $title !== $column->title || $type !== $column->type->value;
        if ($changesSignedData) {
            $this->assertNoFinalProjects($sheet);
        }

        if ($type === ColumnType::Number->value && $column->type !== ColumnType::Number) {
            $invalid = SheetCell::where('column_id', $column->id)->whereNotNull('value')->pluck('value')
                ->contains(fn ($value) => Digits::normalizeNumber($value) === false);
            if ($invalid) {
                throw ValidationException::withMessages(['columnType' => 'این ستون مقدار غیرعددی دارد و نمی‌تواند عددی شود.']);
            }
        }

        $before = ['title' => $column->title, 'type' => $column->type->value, 'locked' => $column->is_locked];
        $column->update(['title' => $title, 'type' => $type, 'is_locked' => $locked]);
        $this->log->record($sheet->id, $user, 'column.update', null, $column->id, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode(['title' => $title, 'type' => $type, 'locked' => $locked], JSON_UNESCAPED_UNICODE));
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

    /** @param list<int|string> $projectIds */
    public function setMonthProjects(User $user, Sheet $sheet, array $projectIds): void
    {
        $this->authorizeManage($user);

        $wanted = Project::whereIn('id', array_map('intval', $projectIds))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $current = SheetProject::where('sheet_id', $sheet->id)->get()->keyBy('project_id');

        $removing = $current->keys()->map(fn ($id) => (int) $id)->diff($wanted);
        foreach ($removing as $projectId) {
            $sheetProject = $current->get($projectId);
            if ($sheetProject->stage !== Stage::Draft || SheetRow::where('sheet_id', $sheet->id)->where('project_id', $projectId)->exists()) {
                $name = Project::find($projectId)?->name;
                throw ValidationException::withMessages(['monthProjects' => "پروژه «{$name}» ردیف یا تایید دارد و نمی‌تواند از این ماه حذف شود."]);
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

    /** @return array{0:string,1:string} */
    private function validateColumn(Sheet $sheet, string $title, string $type, ?int $ignoreId = null): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $errors = [];
        if ($title === '' || mb_strlen($title) > 80) {
            $errors['columnTitle'] = 'عنوان ستون الزامی است (حداکثر ۸۰ کاراکتر).';
        } elseif (SheetColumn::where('sheet_id', $sheet->id)->where('title', $title)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $errors['columnTitle'] = 'ستونی با این عنوان وجود دارد.';
        }
        if (ColumnType::tryFrom($type) === null) {
            $errors['columnType'] = 'نوع ستون معتبر نیست.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$title, $type];
    }

    private function assertNoFinalProjects(Sheet $sheet): void
    {
        if (SheetProject::where('sheet_id', $sheet->id)->where('stage', Stage::Final->value)->exists()) {
            throw ValidationException::withMessages(['column' => 'این شیت لیست نهایی‌شده دارد؛ ساختار ستون‌ها قابل تغییر نیست.']);
        }
    }

    private function assertProjectNotFinal(int $sheetId, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }
        if (SheetProject::where('sheet_id', $sheetId)->where('project_id', $projectId)->where('stage', Stage::Final->value)->exists()) {
            throw ValidationException::withMessages(['project' => 'لیست این پروژه نهایی شده و قابل تغییر نیست.']);
        }
    }

    private function authorizeManage(User $user): void
    {
        if (! $this->access->canManage($user)) {
            throw new AuthorizationException('این کار فقط برای مدیر مجاز است.');
        }
    }
}
