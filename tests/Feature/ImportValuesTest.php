<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Livewire\Sheets\Grid;
use App\Models\ChangeLog;
use App\Models\Project;
use App\Models\SheetCell;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\PersonnelImporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/** Editors fill the open columns of their own projects from Excel; personnel stay untouched. */
class ImportValuesTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->editor = User::factory()->editor($this->projectA)->create();
    }

    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tuka-values-').'.csv';
        $handle = fopen($path, 'w');
        foreach ($lines as $line) {
            fputcsv($handle, $line, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function value(SheetRow $row, $column): ?string
    {
        return SheetCell::where('row_id', $row->id)->where('column_id', $column->id)->value('value');
    }

    public function test_editor_updates_own_rows_and_gets_the_unknown_national_codes_back(): void
    {
        $ownRow = $this->makeRow($this->projectA);
        $ownRow->update(['review_status' => ReviewStatus::Rejected]);
        $stranger = $this->validNationalCode();
        $path = $this->csv([
            ['ردیف', 'نام', 'نام خانوادگی', 'کد ملی', 'کد پرسنلی', 'پروژه', 'اضافه‌کار', 'حقوق پایه', 'توضیحات', 'ستون ناشناس'],
            ['1', 'نام عوض‌شده', 'x', $this->rowA->national_code, '999', 'سپهر', '12', '900000000', 'خوب', 'هرچه'],
            ['2', 'علی', 'غریبه', $stranger, '', '', '7', '', '', ''],
            ['3', 'نفر', 'سپهری', $this->rowB->national_code, '', '', '8', '', '', ''],
            ['4', 'نفر', 'دوم', ltrim($ownRow->national_code, '0'), '', '', '۳٫۰', '', '', ''],
        ]);

        $result = app(PersonnelImporter::class)->import($this->editor, $this->sheet, $path, 'csv');

        $this->assertSame('values', $result['mode']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['rows']);
        $this->assertSame(3, $result['cells']);
        // A person of another project is reported exactly like an unknown one (no hint where they are).
        $this->assertSame([
            ['code' => $stranger, 'name' => 'علی غریبه', 'line' => 3],
            ['code' => $this->rowB->national_code, 'name' => 'نفر سپهری', 'line' => 4],
        ], $result['unknown']);
        $this->assertSame(['حقوق پایه'], $result['lockedColumns']);
        $this->assertSame(['ستون ناشناس'], $result['unknownColumns']);
        $this->assertSame(['کد پرسنلی', 'پروژه'], $result['ignoredFields']);

        $this->assertSame('12', $this->value($this->rowA, $this->openColumn));
        $this->assertSame('خوب', $this->value($this->rowA, $this->textColumn));
        $this->assertSame('3', $this->value($ownRow, $this->openColumn));
        $this->assertNull($this->value($this->rowA, $this->lockedColumn));
        $this->assertNull($this->value($this->rowB, $this->openColumn));

        $fresh = $this->rowA->fresh();
        $this->assertSame([$this->rowA->first_name, $this->projectA->id, null], [$fresh->first_name, $fresh->project_id, $fresh->personnel_code]);
        $this->assertSame(ReviewStatus::Pending, $ownRow->fresh()->review_status);
        $this->assertSame(3, ChangeLog::where('action', 'cell.update')->where('user_id', $this->editor->id)->count());
        $this->assertSame(1, ChangeLog::where('action', 'sheet.import.values')->count());
    }

    public function test_a_value_with_a_fraction_saved_before_may_come_back_unchanged(): void
    {
        SheetCell::create(['row_id' => $this->rowA->id, 'column_id' => $this->openColumn->id, 'value' => '7.5', 'version' => 1]);
        $path = $this->csv([
            ['کد ملی', 'اضافه‌کار', 'توضیحات'],
            [$this->rowA->national_code, '۷٫۵', 'بررسی شد'],
        ]);

        $result = app(PersonnelImporter::class)->import($this->editor, $this->sheet, $path, 'csv');

        $this->assertSame(1, $result['cells']);
        $this->assertSame('7.5', $this->value($this->rowA, $this->openColumn));
        $this->assertSame('بررسی شد', $this->value($this->rowA, $this->textColumn));
    }

    public function test_an_invalid_value_saves_nothing_and_points_to_the_line(): void
    {
        $this->openColumn->update(['min_value' => '0', 'max_value' => '31']);
        $second = $this->makeRow($this->projectA);
        $path = $this->csv([
            ['کد ملی', 'نام', 'نام خانوادگی', 'اضافه‌کار'],
            [$this->rowA->national_code, 'الف', 'ب', '10'],
            [$second->national_code, 'مریم', 'صالحی', '40'],
            ['', 'بی', 'کد', '3'],
            [$this->makeRow($this->projectA)->national_code, 'سینا', 'راد', '۱۲٫۵'],
        ]);

        try {
            app(PersonnelImporter::class)->import($this->editor, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $this->assertSame([
                'سطر ۳ (مریم صالحی): ستون D «اضافه‌کار»: ۴۰ مجاز نیست؛ باید بین ۰ و ۳۱ باشد.',
                'سطر ۴ (بی کد): کد ملی وارد نشده است.',
                'سطر ۵ (سینا راد): ستون D «اضافه‌کار»: «۱۲٫۵» عدد صحیح نیست؛ اعشار مجاز نیست.',
            ], $e->errors()['importRows']);
        }

        $this->assertNull($this->value($this->rowA, $this->openColumn));
    }

    public function test_only_national_code_is_required_and_rows_of_an_approved_project_are_skipped(): void
    {
        $editor = User::factory()->editor($this->projectA, $this->projectB)->create();
        $this->sheetProject($this->projectB)->update(['stage' => Stage::ProjectApproved]);
        $path = $this->csv([
            ['شماره ملی', 'اضافه‌کار'],
            [$this->rowA->national_code, '5'],
            [$this->rowB->national_code, '6'],
        ]);

        $result = app(PersonnelImporter::class)->import($editor, $this->sheet, $path, 'csv');

        $this->assertSame(['سپهر'], $result['closedProjects']);
        $this->assertSame([], $result['unknown']);
        $this->assertTrue($result['manyProjects']);
        $this->assertSame('5', $this->value($this->rowA, $this->openColumn));
        $this->assertNull($this->value($this->rowB, $this->openColumn));
    }

    public function test_a_file_without_fillable_columns_is_explained(): void
    {
        $path = $this->csv([
            ['کد ملی', 'حقوق پایه', 'پاداش'],
            [$this->rowA->national_code, '1', '2'],
        ]);

        try {
            app(PersonnelImporter::class)->import($this->editor, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $messages = $e->errors()['importFile'];
        }

        $this->assertSame('در فایل ستونی نیست که شما بتوانید پر کنید.', $messages[0]);
        $this->assertContains('این عنوان‌ها در لیست حقوق این ماه نیستند: «پاداش».', $messages);
        $this->assertContains('این ستون‌ها قفل‌اند و فقط مدیر آن‌ها را پر می‌کند: «حقوق پایه».', $messages);
    }

    public function test_no_import_after_the_deadline_or_without_an_open_project(): void
    {
        $importer = app(PersonnelImporter::class);
        $this->assertSame('values', $importer->mode($this->editor, $this->sheet));
        $this->assertSame('full', $importer->mode($this->manager, $this->sheet));

        $this->sheetProject($this->projectA)->update(['stage' => Stage::ProjectApproved]);
        $this->assertNull($importer->mode($this->editor, $this->sheet));

        $this->sheetProject($this->projectA)->update(['stage' => Stage::Draft]);
        $this->sheet->update(['deadline_at' => now()->subMinute()]);
        $this->assertNull($importer->mode($this->editor->fresh(), $this->sheet->fresh()));

        $this->expectException(AuthorizationException::class);
        $importer->import($this->editor, $this->sheet->fresh(), $this->csv([['کد ملی'], ['1']]), 'csv');
    }

    public function test_editor_imports_from_the_grid(): void
    {
        $other = Project::factory()->create();
        SheetProject::create(['sheet_id' => $this->sheet->id, 'project_id' => $other->id]);
        $unknown = $this->validNationalCode();
        $content = "کد ملی,نام,نام خانوادگی,اضافه‌کار\n{$this->rowA->national_code},الف,ب,9\n{$unknown},کاوه,مرادی,4\n";

        Livewire::actingAs($this->editor)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->assertSee('ورود مقادیر از اکسل')
            ->call('openImport')
            ->assertSee('ستون‌هایی که شما می‌توانید پر کنید')
            ->set('importFile', UploadedFile::fake()->createWithContent('values.csv', $content))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('۱ خانه در ۱ ردیف به‌روزرسانی شد.')
            ->assertSee('این ۱ کد ملی در پروژه‌ی شما تعریف نشده‌اند و وارد نشدند:')
            ->assertSee($unknown)
            ->assertSee('لطفاً از مدیر بخواهید ابتدا این کد ملی‌ها را به پروژه‌ی شما تخصیص دهد');

        $this->assertSame('9', $this->value($this->rowA, $this->openColumn));

        $viewer = User::factory()->viewer($this->projectA)->create();
        Livewire::actingAs($viewer)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->assertDontSee('ورود مقادیر از اکسل')
            ->call('openImport')
            ->assertForbidden();
    }
}
