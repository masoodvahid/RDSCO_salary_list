<?php

namespace App\Services;

use App\Enums\ColumnType;
use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\ChangeLog;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Support\Digits;
use App\Support\NationalCode;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Excel/CSV import into a monthly list.
 *
 *  - Managers ("full"): personnel and values. People are matched by national code (updated or
 *    created); other headers fill the column with the same title or create a new column.
 *    Required headers: نام، نام خانوادگی، کد ملی. Optional: کد پرسنلی، پروژه.
 *  - Editors and project approvers ("values"): values only, for personnel of their own projects whose
 *    list is still open. Only «کد ملی» is required. National codes outside their projects, locked and
 *    unknown columns are skipped and reported; personnel fields never change.
 *
 * Invalid data is all-or-nothing: if any line has a problem nothing is written and each problem is
 * reported with its line number, the person and the offending value.
 */
final class PersonnelImporter
{
    public const MODE_FULL = 'full';

    public const MODE_VALUES = 'values';

    private const IDENTITY_HEADERS = [
        'نام' => 'first_name',
        'نامخانوادگی' => 'last_name',
        'فامیلی' => 'last_name',
        'کدپرسنلی' => 'personnel_code',
        'شمارهپرسنلی' => 'personnel_code',
        'کدملی' => 'national_code',
        'شمارهملی' => 'national_code',
        'پروژه' => 'project',
        'نامپروژه' => 'project',
    ];

    private const FIELD_LABELS = [
        'first_name' => ['نام', '«نام»'],
        'last_name' => ['نام خانوادگی', '«نام خانوادگی» یا «فامیلی»'],
        'personnel_code' => ['کد پرسنلی', '«کد پرسنلی» یا «شماره پرسنلی»'],
        'national_code' => ['کد ملی', '«کد ملی» یا «شماره ملی»'],
        'project' => ['پروژه', '«پروژه»'],
    ];

    /** Columns our own Excel export adds that are not data: row number and review status. */
    private const IGNORED_HEADERS = ['ردیف', 'وضعیتبررسی'];

    private const MAX_ROWS = 5000;

    private const MAX_ERRORS_SHOWN = 50;

    public function __construct(
        private readonly SheetAccess $access,
        private readonly ChangeLogger $log,
        private readonly SheetEditor $editor,
    ) {}

    /** MODE_FULL for managers, MODE_VALUES for members who can still fill their lists, otherwise null. */
    public function mode(User $user, Sheet $sheet): ?string
    {
        if ($this->access->canManage($user)) {
            return self::MODE_FULL;
        }

        return $this->access->canImportValues($user, $sheet) ? self::MODE_VALUES : null;
    }

    /** @return array<string, mixed> summary for the result dialog; always has 'mode' */
    public function import(User $user, Sheet $sheet, string $path, string $extension): array
    {
        $mode = $this->mode($user, $sheet);
        if ($mode === null) {
            throw new AuthorizationException('ورود از اکسل برای شما مجاز نیست: پروژه‌ی باز یا مهلت ویرایش ندارید.');
        }

        $lines = $this->read($path, strtolower($extension));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['importFile' => 'فایل خالی است یا فقط سطر عنوان دارد.']);
        }
        $header = $lines[array_key_first($lines)];
        unset($lines[array_key_first($lines)]);
        if (count($lines) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['importFile' => 'حداکثر '.Digits::toPersian(self::MAX_ROWS).' ردیف در هر فایل. فایل را چند قسمت کنید.']);
        }

        $layout = $this->layout($header, $mode);

        return $mode === self::MODE_FULL
            ? $this->importPeople($user, $sheet, $lines, $layout)
            : $this->importValues($user, $sheet, $lines, $layout);
    }

    // ------------------------------------------------------------------ managers: people + values

    /**
     * @param  array<int, list<string>>  $lines  keyed by line number in the file
     * @param  array{map: array<string, int>, extra: array<int, string>}  $layout
     */
    private function importPeople(User $user, Sheet $sheet, array $lines, array $layout): array
    {
        ['map' => $map, 'extra' => $extraColumns] = $layout;

        $columnByTitle = SheetColumn::where('sheet_id', $sheet->id)->get()->keyBy(fn ($c) => self::normalizeHeader($c->title));
        $sheetProjects = SheetProject::where('sheet_id', $sheet->id)->with('project')->get();
        $projectByName = $sheetProjects->keyBy(fn ($sp) => self::normalizeHeader($sp->project->name));
        $sheetProjectById = $sheetProjects->keyBy('project_id');
        $existingRows = SheetRow::where('sheet_id', $sheet->id)->get()->keyBy('national_code');
        $monthProjects = $sheetProjects->pluck('project.name')->sort()->values();

        // Type of each column that will be created: number unless a value is not numeric.
        $newColumnTypes = [];
        foreach ($extraColumns as $index => $title) {
            if ($columnByTitle->has(self::normalizeHeader($title))) {
                continue;
            }
            $numeric = true;
            foreach ($lines as $line) {
                $value = trim((string) ($line[$index] ?? ''));
                if ($value !== '' && ! $this->isTotalsLine($line, $map) && Digits::normalizeNumber($value) === false) {
                    $numeric = false;
                    break;
                }
            }
            $newColumnTypes[$index] = $numeric ? ColumnType::Number : ColumnType::Text;
        }

        $errors = [];
        $parsed = [];
        $seen = [];
        $total = 0;
        foreach ($lines as $lineNo => $line) {
            if ($this->isTotalsLine($line, $map)) {
                continue;
            }
            $total++;
            $who = $this->who($line, $map, $lineNo);

            $first = $this->cell($line, $map, 'first_name');
            $last = $this->cell($line, $map, 'last_name');
            $rawCode = $this->cell($line, $map, 'national_code');
            $personnel = isset($map['personnel_code']) ? Digits::toEnglish($this->cell($line, $map, 'personnel_code')) : null;
            $projectName = $this->cell($line, $map, 'project');

            $problems = [];
            if ($first === '') {
                $problems[] = '«نام» خالی است';
            }
            if ($last === '') {
                $problems[] = '«نام خانوادگی» خالی است';
            }
            if ($personnel !== null && mb_strlen($personnel) > 20) {
                $problems[] = "کد پرسنلی «{$personnel}» بیشتر از ۲۰ کاراکتر است";
            }

            $national = null;
            if (($problem = NationalCode::problem($rawCode)) !== null) {
                $problems[] = $problem;
            } else {
                $national = NationalCode::normalize($rawCode);
                if (isset($seen[$national])) {
                    $problems[] = "کد ملی {$national} تکراری است؛ در {$seen[$national]} هم آمده";
                }
            }

            $existing = $national !== null ? $existingRows->get($national) : null;
            $projectId = null;
            if ($projectName !== '') {
                $sheetProject = $projectByName->get(self::normalizeHeader($projectName));
                if (! $sheetProject) {
                    $problems[] = "پروژه «{$projectName}» در پروژه‌های این ماه نیست"
                        .($monthProjects->isEmpty() ? '' : ' (پروژه‌های این ماه: '.$monthProjects->take(12)->join('، ').')');
                } elseif ($sheetProject->stage->isLocked()) {
                    $problems[] = "لیست پروژه «{$projectName}» تایید مدیرعامل گرفته و قفل است";
                } else {
                    $projectId = $sheetProject->project_id;
                }
            }
            if ($existing?->project_id && $sheetProjectById->get($existing->project_id)?->stage->isLocked()) {
                $problems[] = 'این نفر در لیست قفل‌شده‌ی پروژه «'.$sheetProjectById->get($existing->project_id)->project->name.'» (تایید مدیرعامل یا نهایی) است و قابل تغییر نیست';
            }

            $values = [];
            foreach ($extraColumns as $index => $title) {
                $column = $columnByTitle->get(self::normalizeHeader($title));
                [$value, $problem] = $this->parseValue(trim((string) ($line[$index] ?? '')), $column?->type ?? $newColumnTypes[$index], $column, $title, $index);
                if ($problem !== null) {
                    $problems[] = $problem;

                    continue;
                }
                $values[$index] = $value;
            }

            if ($national !== null && ! isset($seen[$national])) {
                $seen[$national] = $who;
            }
            if ($problems !== []) {
                $errors[] = "{$who}: ".implode('؛ ', array_unique($problems)).'.';

                continue;
            }

            $parsed[] = compact('first', 'last', 'national', 'personnel', 'projectId', 'values');
        }

        if ($errors !== []) {
            $this->fail($errors, $total);
        }

        return DB::transaction(function () use ($user, $sheet, $parsed, $extraColumns, $columnByTitle, $newColumnTypes, $existingRows) {
            $created = $updated = 0;

            $columnIds = [];
            $position = (int) SheetColumn::where('sheet_id', $sheet->id)->max('position');
            foreach ($extraColumns as $index => $title) {
                $column = $columnByTitle->get(self::normalizeHeader($title));
                if (! $column) {
                    $column = SheetColumn::create([
                        'sheet_id' => $sheet->id,
                        'title' => mb_substr($title, 0, 80),
                        'type' => $newColumnTypes[$index]->value,
                        'is_locked' => false,
                        'position' => ++$position,
                    ]);
                }
                $columnIds[$index] = $column->id;
            }

            // Existing values of the imported columns, loaded once instead of one query per cell.
            $current = SheetCell::query()
                ->whereIn('row_id', $existingRows->pluck('id'))
                ->whereIn('column_id', array_values($columnIds))
                ->get()
                ->keyBy(fn (SheetCell $cell) => $cell->row_id.':'.$cell->column_id);
            $now = now();
            $inserts = [];

            $rowPosition = (int) SheetRow::where('sheet_id', $sheet->id)->max('position');
            foreach ($parsed as $item) {
                $row = $existingRows->get($item['national']);
                $attributes = [
                    'first_name' => $item['first'],
                    'last_name' => $item['last'],
                    'personnel_code' => $item['personnel'] !== null ? ($item['personnel'] === '' ? null : $item['personnel']) : $row?->personnel_code,
                    'project_id' => $item['projectId'] ?? $row?->project_id,
                ];

                if ($row) {
                    $row->update($attributes);
                    $updated++;
                } else {
                    $row = SheetRow::create($attributes + [
                        'sheet_id' => $sheet->id,
                        'national_code' => $item['national'],
                        'position' => ++$rowPosition,
                        'review_status' => ReviewStatus::Pending,
                    ]);
                    $created++;
                }

                foreach ($item['values'] as $index => $value) {
                    $cell = $current->get($row->id.':'.$columnIds[$index]);
                    if ($cell) {
                        if ($cell->value !== $value) {
                            $cell->update(['value' => $value, 'version' => $cell->version + 1, 'updated_by' => $user->id]);
                        }
                    } elseif ($value !== null) {
                        $inserts[] = ['row_id' => $row->id, 'column_id' => $columnIds[$index], 'value' => $value, 'version' => 1, 'updated_by' => $user->id, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }
            foreach (array_chunk($inserts, 500) as $chunk) {
                SheetCell::insert($chunk);
            }

            $newColumns = count(array_filter($newColumnTypes));
            $this->log->record($sheet->id, $user, 'sheet.import', meta: compact('created', 'updated', 'newColumns'));

            return ['mode' => self::MODE_FULL, 'created' => $created, 'updated' => $updated, 'columns' => $newColumns];
        });
    }

    // ------------------------------------------------------------------ editors: values of own projects

    /**
     * @param  array<int, list<string>>  $lines
     * @param  array{map: array<string, int>, extra: array<int, string>}  $layout
     */
    private function importValues(User $user, Sheet $sheet, array $lines, array $layout): array
    {
        ['map' => $map, 'extra' => $extraColumns] = $layout;

        $sheetColumns = SheetColumn::where('sheet_id', $sheet->id)->get()->keyBy(fn ($c) => self::normalizeHeader($c->title));
        $columns = [];
        $unknownColumns = [];
        $lockedColumns = [];
        foreach ($extraColumns as $index => $title) {
            $column = $sheetColumns->get(self::normalizeHeader($title));
            if (! $column) {
                $unknownColumns[] = $title;
            } elseif ($column->is_locked) {
                $lockedColumns[] = $column->title;
            } else {
                $columns[$index] = $column;
            }
        }
        // Name columns only help identify people in messages; these two are personnel data only managers change.
        $ignoredFields = array_values(array_map(fn ($field) => self::FIELD_LABELS[$field][0], array_intersect(['personnel_code', 'project'], array_keys($map))));

        if ($columns === []) {
            $messages = ['در فایل ستونی نیست که شما بتوانید پر کنید.'];
            if ($unknownColumns !== []) {
                $messages[] = 'این عنوان‌ها در لیست حقوق این ماه نیستند: '.self::quoteList($unknownColumns).'.';
            }
            if ($lockedColumns !== []) {
                $messages[] = 'این ستون‌ها قفل‌اند و فقط مدیر آن‌ها را پر می‌کند: '.self::quoteList($lockedColumns).'.';
            }
            $messages[] = 'عنوان ستون‌های فایل باید مثل عنوان ستون‌های لیست حقوق باشد. ساده‌ترین راه: از همین صفحه «خروجی اکسل» بگیرید، پر کنید و همان را وارد کنید.';
            throw ValidationException::withMessages(['importFile' => $messages]);
        }

        $rows = $this->access->visibleRows($user, $sheet)->get()->keyBy('national_code');
        $sheetProjects = SheetProject::where('sheet_id', $sheet->id)->with('project')->get()->keyBy('project_id');
        $cells = SheetCell::whereIn('row_id', $rows->pluck('id'))
            ->whereIn('column_id', array_map(fn (SheetColumn $c) => $c->id, $columns))
            ->get()
            ->keyBy(fn (SheetCell $c) => $c->row_id.':'.$c->column_id);

        $errors = [];
        $seen = [];
        $unknown = [];
        $closed = [];
        $changes = [];
        $matched = 0;
        $total = 0;
        foreach ($lines as $lineNo => $line) {
            if ($this->isTotalsLine($line, $map)) {
                continue;
            }
            $total++;
            $who = $this->who($line, $map, $lineNo);

            $rawCode = $this->cell($line, $map, 'national_code');
            if (($problem = NationalCode::problem($rawCode)) !== null) {
                $errors[] = "{$who}: {$problem}.";

                continue;
            }
            $code = NationalCode::normalize($rawCode);
            if (isset($seen[$code])) {
                $errors[] = "{$who}: کد ملی {$code} تکراری است؛ در {$seen[$code]} هم آمده.";

                continue;
            }
            $seen[$code] = $who;

            $row = $rows->get($code);
            if (! $row) {
                $unknown[] = ['code' => $code, 'name' => $this->fullName($line, $map), 'line' => $lineNo];

                continue;
            }

            $sheetProject = $sheetProjects->get($row->project_id);
            $problems = [];
            $rowValues = [];
            foreach ($columns as $index => $column) {
                if (! $this->access->canEditCell($user, $sheet, $row, $column, $sheetProject)) {
                    $closed[$sheetProject?->project?->name ?? '—'] = true;
                    $rowValues = null;
                    break;
                }
                [$value, $problem] = $this->parseValue(trim((string) ($line[$index] ?? '')), $column->type, $column, $column->title, $index);
                if ($problem !== null) {
                    $problems[] = $problem;

                    continue;
                }
                $rowValues[] = [$column, $value];
            }
            if ($rowValues === null) {
                continue; // the list of this project is no longer open; reported once per project
            }
            if ($problems !== []) {
                $errors[] = "{$who}: ".implode('؛ ', $problems).'.';

                continue;
            }

            $matched++;
            foreach ($rowValues as [$column, $value]) {
                $cell = $cells->get($row->id.':'.$column->id);
                if ($cell?->value !== $value && ($cell || $value !== null)) {
                    $changes[] = [$row, $column, $value];
                }
            }
        }

        if ($errors !== []) {
            $this->fail($errors, $total);
        }

        $changedRows = [];
        $cellCount = 0;
        try {
            DB::transaction(function () use ($user, $sheet, $changes, $unknown, &$changedRows, &$cellCount, &$closed) {
                // Lock the lists being written: an approval that starts now waits for this import (and signs
                // its result); one that got here first has moved the list on, and its rows are skipped.
                $projectIds = array_values(array_unique(array_map(fn ($change) => (int) $change[0]->project_id, $changes)));
                $locked = SheetProject::where('sheet_id', $sheet->id)->whereIn('project_id', $projectIds)
                    ->with('project')->lockForUpdate()->get()->keyBy('project_id');
                if ($changes !== [] && $sheet->isPastDeadline()) {
                    throw ValidationException::withMessages(['importFile' => 'مهلت ویرایش همین حالا تمام شد و چیزی ذخیره نشد.']);
                }
                foreach ($locked as $sheetProject) {
                    if ($sheetProject->stage !== Stage::Draft) {
                        $closed[$sheetProject->project?->name ?? '—'] = true;
                    }
                }
                $changes = array_values(array_filter($changes, fn ($change) => $locked->get($change[0]->project_id)?->stage === Stage::Draft));

                $current = $changes === [] ? collect() : SheetCell::query()
                    ->whereIn('row_id', array_values(array_unique(array_map(fn ($change) => $change[0]->id, $changes))))
                    ->whereIn('column_id', array_values(array_unique(array_map(fn ($change) => $change[1]->id, $changes))))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn (SheetCell $cell) => $cell->row_id.':'.$cell->column_id);

                $now = now();
                $ip = app()->runningInConsole() ? null : request()->ip();
                $inserts = [];
                $logs = [];
                foreach ($changes as [$row, $column, $value]) {
                    $cell = $current->get($row->id.':'.$column->id);
                    $old = $cell?->value;
                    if ($old === $value || (! $cell && $value === null)) {
                        continue;
                    }
                    if ($cell) {
                        $cell->update(['value' => $value, 'version' => $cell->version + 1, 'updated_by' => $user->id]);
                    } else {
                        $inserts[] = ['row_id' => $row->id, 'column_id' => $column->id, 'value' => $value, 'version' => 1, 'updated_by' => $user->id, 'created_at' => $now, 'updated_at' => $now];
                    }
                    $logs[] = [
                        'sheet_id' => $sheet->id, 'row_id' => $row->id, 'column_id' => $column->id, 'user_id' => $user->id,
                        'action' => 'cell.update', 'old_value' => $old, 'new_value' => $value,
                        'meta' => json_encode(['source' => 'import']), 'ip' => $ip, 'created_at' => $now,
                    ];
                    $cellCount++;
                    if (! isset($changedRows[$row->id])) {
                        $changedRows[$row->id] = true;
                        $this->editor->resetRejectedReview($user, $sheet, $row);
                    }
                }
                foreach (array_chunk($inserts, 500) as $chunk) {
                    SheetCell::insert($chunk);
                }
                foreach (array_chunk($logs, 500) as $chunk) {
                    ChangeLog::insert($chunk);
                }

                $this->log->record($sheet->id, $user, 'sheet.import.values', meta: [
                    'rows' => count($changedRows),
                    'cells' => $cellCount,
                    'unknown' => count($unknown),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['importFile' => 'هم‌زمان کس دیگری همین خانه‌ها را ثبت کرد و چیزی ذخیره نشد. فایل را دوباره وارد کنید.']);
        }

        return [
            'mode' => self::MODE_VALUES,
            'matched' => $matched,
            'rows' => count($changedRows),
            'cells' => $cellCount,
            'unknown' => $unknown,
            'unknownColumns' => $unknownColumns,
            'lockedColumns' => $lockedColumns,
            'ignoredFields' => $ignoredFields,
            'closedProjects' => array_keys($closed),
            'manyProjects' => count($user->projectIds()) > 1,
        ];
    }

    // ------------------------------------------------------------------ shared

    /**
     * Maps the header line: identity fields by alias, everything else as a column title.
     *
     * @param  list<string>  $header
     * @return array{map: array<string, int>, extra: array<int, string>}
     */
    private function layout(array $header, string $mode): array
    {
        $headers = array_map(fn ($h) => trim((string) $h), $header);
        $map = [];
        $extra = [];
        $taken = [];
        $errors = [];

        foreach ($headers as $index => $title) {
            if ($title === '') {
                continue;
            }
            $key = self::normalizeHeader($title);
            if (in_array($key, self::IGNORED_HEADERS, true)) {
                continue;
            }
            $field = self::IDENTITY_HEADERS[$key] ?? null;
            $slot = $field !== null ? "field:{$field}" : "column:{$key}";
            if (isset($taken[$slot])) {
                $errors[] = "ستون «{$title}» دو بار در فایل آمده (ستون‌های ".self::columnLetter($taken[$slot]).' و '.self::columnLetter($index).')؛ یکی را حذف یا عنوانش را عوض کنید.';

                continue;
            }
            $taken[$slot] = $index;
            if ($field !== null) {
                $map[$field] = $index;
            } else {
                $extra[$index] = $title;
            }
        }

        $required = $mode === self::MODE_FULL ? ['first_name', 'last_name', 'national_code'] : ['national_code'];
        $missing = array_values(array_diff($required, array_keys($map)));
        foreach ($missing as $field) {
            [$label, $accepted] = self::FIELD_LABELS[$field];
            $errors[] = "سطر اول فایل (عنوان ستون‌ها) ستون «{$label}» را ندارد. عنوان قابل قبول: {$accepted}.";
        }
        if ($missing !== []) {
            $found = array_values(array_filter($headers, fn ($h) => $h !== ''));
            $errors[] = 'عنوان‌هایی که در سطر اول فایل پیدا شد: '.self::quoteList(array_slice($found, 0, 25)).(count($found) > 25 ? ' و …' : '').'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['importFile' => $errors]);
        }

        return ['map' => $map, 'extra' => $extra];
    }

    /** @return array{0: string|null, 1: string|null} [stored value, problem] */
    private function parseValue(string $raw, ColumnType $type, ?SheetColumn $column, string $title, int $index): array
    {
        $ref = 'ستون '.self::columnLetter($index)." «{$title}»";

        if ($type === ColumnType::Number) {
            $value = Digits::normalizeNumber($raw);
            if ($value === false) {
                return [null, "{$ref}: «{$raw}» عدد نیست"];
            }
            if (Digits::normalizeInteger($value) === false) {
                return [null, "{$ref}: «{$raw}» عدد صحیح نیست؛ اعشار مجاز نیست"];
            }
            if ($column?->isOutOfRange($value)) {
                return [null, "{$ref}: ".Digits::money($value)." مجاز نیست؛ باید {$column->rangeLabel()} باشد"];
            }

            return [$value, null];
        }

        return [$raw === '' ? null : mb_substr($raw, 0, 500), null];
    }

    /** @param list<string> $errors one entry per line with problems */
    private function fail(array $errors, int $total): never
    {
        $shown = array_slice($errors, 0, self::MAX_ERRORS_SHOWN);
        if (count($errors) > self::MAX_ERRORS_SHOWN) {
            $shown[] = 'و '.Digits::toPersian(count($errors) - self::MAX_ERRORS_SHOWN).' سطر دیگر با خطا.';
        }

        throw ValidationException::withMessages([
            'importFile' => Digits::toPersian(count($errors)).' سطر از '.Digits::toPersian($total).' سطر فایل خطا دارد و هیچ اطلاعاتی ذخیره نشد. این سطرها را در فایل اصلاح و دوباره بارگذاری کنید:',
            'importRows' => $shown,
        ]);
    }

    /** @param list<string> $line */
    private function cell(array $line, array $map, string $field): string
    {
        return isset($map[$field]) ? trim((string) ($line[$map[$field]] ?? '')) : '';
    }

    private function fullName(array $line, array $map): string
    {
        return trim($this->cell($line, $map, 'first_name').' '.$this->cell($line, $map, 'last_name'));
    }

    /** "سطر ۱۲ (علی رضایی)" — the line number in Excel plus the name, so the line is easy to find. */
    private function who(array $line, array $map, int $lineNo): string
    {
        $name = $this->fullName($line, $map);

        return 'سطر '.Digits::toPersian($lineNo).($name !== '' ? " ({$name})" : '');
    }

    /** The totals line of our own export: «جمع» in a name column and no national code. */
    private function isTotalsLine(array $line, array $map): bool
    {
        return $this->cell($line, $map, 'national_code') === ''
            && in_array('جمع', [$this->cell($line, $map, 'first_name'), $this->cell($line, $map, 'last_name')], true);
    }

    /**
     * Non-empty lines of the first sheet, keyed by their line number in the file.
     *
     * @return array<int, list<string>>
     */
    private function read(string $path, string $extension): array
    {
        $reader = match ($extension) {
            // Empty rows are kept so line numbers in messages match the row numbers in Excel.
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            'csv', 'txt' => new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            default => throw ValidationException::withMessages(['importFile' => 'فقط فایل XLSX یا CSV پذیرفته می‌شود.']),
        };

        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $lineNo = 0;
                $emptyRun = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $lineNo++;
                    $cells = array_map(fn ($value) => self::stringify($value), $row->toArray());
                    if (array_filter($cells, fn ($v) => trim($v) !== '') === []) {
                        if (++$emptyRun > 1000) {
                            break; // formatted but empty rows down the sheet
                        }

                        continue;
                    }
                    $emptyRun = 0;
                    $rows[$lineNo] = $cells;
                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }
                }
                break; // first sheet only
            }
            $reader->close();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages(['importFile' => 'فایل خوانده نشد. فایل را در اکسل با قالب XLSX ذخیره کنید و دوباره امتحان کنید.']);
        }

        return $rows;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return implode('', array_map(fn ($part) => is_object($part) && property_exists($part, 'text') ? (string) $part->text : (is_scalar($part) ? (string) $part : ''), $value));
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /** Excel column letter of a 0-based index: 0 → A, 26 → AA. */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /** @param list<string> $items */
    private static function quoteList(array $items): string
    {
        return implode('، ', array_map(fn ($item) => "«{$item}»", $items));
    }

    /** Compares Persian headers loosely: no spaces/ZWNJ, Arabic ي/ك folded to Persian. */
    public static function normalizeHeader(string $value): string
    {
        return str_replace([' ', "\u{200C}", "\u{00A0}", 'ي', 'ك', 'ة'], ['', '', '', 'ی', 'ک', 'ه'], mb_strtolower(trim($value)));
    }
}
