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
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Imports personnel (and optionally values) from XLSX/CSV into a monthly sheet.
 * All-or-nothing: if any row is invalid nothing is written and every problem is reported.
 *
 * Required headers: نام، نام خانوادگی، کد ملی. Optional: کد پرسنلی، پروژه.
 * Any other header is matched to a sheet column by title, or becomes a new column.
 */
final class PersonnelImporter
{
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

    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly SheetAccess $access,
        private readonly ChangeLogger $log,
    ) {}

    /** @return array{created:int, updated:int, columns:int} */
    public function import(User $user, Sheet $sheet, string $path, string $extension): array
    {
        if (! $this->access->canManage($user)) {
            throw new AuthorizationException('ورود از اکسل فقط برای مدیر مجاز است.');
        }

        $table = $this->read($path, strtolower($extension));
        if (count($table) < 2) {
            throw ValidationException::withMessages(['importFile' => 'فایل خالی است یا فقط سطر عنوان دارد.']);
        }

        $headers = array_map(fn ($h) => trim((string) $h), array_shift($table));
        $map = [];
        $extraColumns = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $key = self::normalizeHeader($header);
            if (isset(self::IDENTITY_HEADERS[$key])) {
                $map[self::IDENTITY_HEADERS[$key]] = $index;
            } else {
                $extraColumns[$index] = $header;
            }
        }

        $missing = array_diff(['first_name', 'last_name', 'national_code'], array_keys($map));
        if ($missing !== []) {
            throw ValidationException::withMessages(['importFile' => 'سطر اول فایل باید ستون‌های «نام»، «نام خانوادگی» و «کد ملی» را داشته باشد.']);
        }
        if (count($table) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['importFile' => 'حداکثر '.Digits::toPersian(self::MAX_ROWS).' ردیف در هر فایل.']);
        }

        $sheetColumns = SheetColumn::where('sheet_id', $sheet->id)->get();
        $columnByTitle = $sheetColumns->keyBy(fn ($c) => self::normalizeHeader($c->title));
        $sheetProjects = SheetProject::where('sheet_id', $sheet->id)->with('project')->get();
        $projectByName = $sheetProjects->keyBy(fn ($sp) => self::normalizeHeader($sp->project->name));
        $existingRows = SheetRow::where('sheet_id', $sheet->id)->get()->keyBy('national_code');

        // Decide the type of columns that will be created.
        $newColumnTypes = [];
        foreach ($extraColumns as $index => $title) {
            if ($columnByTitle->has(self::normalizeHeader($title))) {
                continue;
            }
            $numeric = true;
            foreach ($table as $line) {
                $value = trim((string) ($line[$index] ?? ''));
                if ($value !== '' && Digits::normalizeNumber($value) === false) {
                    $numeric = false;
                    break;
                }
            }
            $newColumnTypes[$index] = $numeric ? ColumnType::Number : ColumnType::Text;
        }

        // Validate everything first.
        $errors = [];
        $parsed = [];
        $seen = [];
        foreach ($table as $i => $line) {
            $lineNo = $i + 2;
            if (array_filter($line, fn ($v) => trim((string) $v) !== '') === []) {
                continue;
            }

            $first = trim((string) ($line[$map['first_name']] ?? ''));
            $last = trim((string) ($line[$map['last_name']] ?? ''));
            $national = NationalCode::normalize((string) ($line[$map['national_code']] ?? ''));
            $personnel = isset($map['personnel_code']) ? trim(Digits::toEnglish((string) ($line[$map['personnel_code']] ?? ''))) : null;
            $projectName = isset($map['project']) ? trim((string) ($line[$map['project']] ?? '')) : '';

            $problems = [];
            if ($first === '' || $last === '') {
                $problems[] = 'نام یا نام خانوادگی خالی است';
            }
            if (! NationalCode::isValid($national)) {
                $problems[] = 'کد ملی نامعتبر';
            } elseif (isset($seen[$national])) {
                $problems[] = 'کد ملی تکراری (سطر '.Digits::toPersian($seen[$national]).')';
            }

            $projectId = null;
            if ($projectName !== '') {
                $sheetProject = $projectByName->get(self::normalizeHeader($projectName));
                if (! $sheetProject) {
                    $problems[] = "پروژه «{$projectName}» در پروژه‌های این ماه نیست";
                } elseif ($sheetProject->stage === Stage::Final) {
                    $problems[] = "لیست پروژه «{$projectName}» نهایی شده است";
                } else {
                    $projectId = $sheetProject->project_id;
                }
            }

            $values = [];
            foreach ($extraColumns as $index => $title) {
                $raw = trim((string) ($line[$index] ?? ''));
                $column = $columnByTitle->get(self::normalizeHeader($title));
                $type = $column?->type ?? $newColumnTypes[$index];
                if ($type === ColumnType::Number) {
                    $value = Digits::normalizeNumber($raw);
                    if ($value === false) {
                        $problems[] = "مقدار «{$title}» عدد نیست";

                        continue;
                    }
                    if ($column?->isOutOfRange($value)) {
                        $problems[] = "مقدار «{$title}» باید {$column->rangeLabel()} باشد";

                        continue;
                    }
                } else {
                    $value = $raw === '' ? null : mb_substr($raw, 0, 500);
                }
                $values[$index] = $value;
            }

            if ($problems !== []) {
                $errors[] = 'سطر '.Digits::toPersian($lineNo).': '.implode('، ', array_unique($problems));

                continue;
            }

            $seen[$national] = $lineNo;
            $parsed[] = compact('first', 'last', 'national', 'personnel', 'projectId', 'values');
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, 15);
            if (count($errors) > 15) {
                $shown[] = 'و '.Digits::toPersian(count($errors) - 15).' خطای دیگر.';
            }
            throw ValidationException::withMessages(['importFile' => $shown]);
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
                    $cell = SheetCell::where('row_id', $row->id)->where('column_id', $columnIds[$index])->first();
                    if ($cell) {
                        if ($cell->value !== $value) {
                            $cell->update(['value' => $value, 'version' => $cell->version + 1, 'updated_by' => $user->id]);
                        }
                    } elseif ($value !== null) {
                        SheetCell::create(['row_id' => $row->id, 'column_id' => $columnIds[$index], 'value' => $value, 'version' => 1, 'updated_by' => $user->id]);
                    }
                }
            }

            $newColumns = count(array_filter($newColumnTypes));
            $this->log->record($sheet->id, $user, 'sheet.import', meta: compact('created', 'updated', 'newColumns'));

            return ['created' => $created, 'updated' => $updated, 'columns' => $newColumns];
        });
    }

    /** @return list<list<string>> */
    private function read(string $path, string $extension): array
    {
        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader,
            default => throw ValidationException::withMessages(['importFile' => 'فقط فایل XLSX یا CSV پذیرفته می‌شود.']),
        };

        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($value) => self::stringify($value), $row->toArray());
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

        // Drop leading empty lines so the first non-empty line is the header.
        while ($rows !== [] && array_filter($rows[0], fn ($v) => $v !== '') === []) {
            array_shift($rows);
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

    /** Compares Persian headers loosely: no spaces/ZWNJ, Arabic ي/ك folded to Persian. */
    public static function normalizeHeader(string $value): string
    {
        return str_replace([' ', "\u{200C}", "\u{00A0}", 'ي', 'ك', 'ة'], ['', '', '', 'ی', 'ک', 'ه'], mb_strtolower(trim($value)));
    }
}
