<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\PersonnelImporter;
use App\Services\SheetExporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tuka-import-').'.csv';
        $handle = fopen($path, 'w');
        foreach ($lines as $line) {
            fputcsv($handle, $line, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    public function test_import_creates_rows_matches_columns_and_adds_new_ones(): void
    {
        $code1 = $this->validNationalCode();
        $code2 = $this->validNationalCode();
        $path = $this->csv([
            ['نام', 'نام خانوادگی', 'کد ملی', 'کد پرسنلی', 'پروژه', 'حقوق پایه', 'پاداش'],
            ['علی', 'رضایی', $code1, '101', 'دماوند', '۱۵۰٬۰۰۰٬۰۰۰', '500'],
            ['سارا', 'حسینی', ltrim($code2, '0'), '', 'سپهر', '140000000', ''],
        ]);

        $result = app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');

        $this->assertSame(['mode' => 'full', 'created' => 2, 'updated' => 0, 'columns' => 1], $result);
        $ali = SheetRow::where('sheet_id', $this->sheet->id)->where('national_code', $code1)->firstOrFail();
        $this->assertSame($this->projectA->id, $ali->project_id);
        $this->assertSame('150000000', SheetCell::where('row_id', $ali->id)->where('column_id', $this->lockedColumn->id)->value('value'));
        $this->assertTrue(SheetColumn::where('sheet_id', $this->sheet->id)->where('title', 'پاداش')->exists());
        $this->assertTrue(SheetRow::where('national_code', $code2)->exists());
    }

    public function test_invalid_rows_abort_the_whole_import(): void
    {
        $before = SheetRow::count();
        $path = $this->csv([
            ['نام', 'نام خانوادگی', 'کد ملی', 'پروژه'],
            ['علی', 'رضایی', $this->validNationalCode(), 'دماوند'],
            ['بد', 'کد', '1234567890', 'دماوند'],
            ['بی', 'پروژه', $this->validNationalCode(), 'ناموجود'],
        ]);

        try {
            app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('۲ سطر از ۳ سطر فایل خطا دارد', $e->errors()['importFile'][0]);
            $this->assertCount(2, $e->errors()['importRows']);
        }

        $this->assertSame($before, SheetRow::count());
    }

    public function test_viewers_cannot_import(): void
    {
        $viewer = User::factory()->viewer($this->projectA)->create();
        $path = $this->csv([['نام', 'نام خانوادگی', 'کد ملی'], ['الف', 'ب', $this->validNationalCode()]]);

        $this->assertNull(app(PersonnelImporter::class)->mode($viewer, $this->sheet));
        $this->expectException(AuthorizationException::class);
        app(PersonnelImporter::class)->import($viewer, $this->sheet, $path, 'csv');
    }

    public function test_errors_name_the_line_the_person_the_value_and_the_reason(): void
    {
        $this->openColumn->update(['min_value' => '0', 'max_value' => '120']);
        $good = $this->validNationalCode();
        $checksum = substr($good, 0, 9).((int) $good[9] + 1) % 10;
        $path = $this->csv([
            ['نام', 'نام خانوادگی', 'کد ملی', 'پروژه', 'اضافه‌کار', 'پاداش'],
            ['علی', 'رضایی', $good, 'دماوند', '10', '5'],
            ['سارا', 'حسینی', '12a4567890', 'دماوند', '', ''],
            ['رضا', 'کرمی', '123', '', '', ''],
            ['مینا', 'نوری', $checksum, '', '', ''],
            ['حسن', 'تکراری', $good, '', '', ''],
            ['', 'بی‌نام', $this->validNationalCode(), 'ناموجود', 'ده', ''],
            ['زهرا', 'بالا', $this->validNationalCode(), '', '150', ''],
            ['کیان', 'متنی', $this->validNationalCode(), '', '', 'زیاد'],
        ]);

        try {
            app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $rows = $e->errors()['importRows'];
        }

        $this->assertSame('سطر ۳ (سارا حسینی): کد ملی «12a4567890» باید فقط عدد باشد.', $rows[0]);
        $this->assertSame('سطر ۴ (رضا کرمی): کد ملی «123» کمتر از ۱۰ رقم است (۳ رقم).', $rows[1]);
        $this->assertStringContainsString("سطر ۵ (مینا نوری): کد ملی «{$checksum}» معتبر نیست؛ رقم کنترل", $rows[2]);
        $this->assertSame("سطر ۶ (حسن تکراری): کد ملی {$good} تکراری است؛ در سطر ۲ (علی رضایی) هم آمده.", $rows[3]);
        $this->assertStringContainsString('سطر ۷ (بی‌نام): «نام» خالی است', $rows[4]);
        $this->assertStringContainsString('پروژه «ناموجود» در پروژه‌های این ماه نیست (پروژه‌های این ماه: دماوند، سپهر)', $rows[4]);
        $this->assertStringContainsString('ستون E «اضافه‌کار»: «ده» عدد نیست', $rows[4]);
        $this->assertSame('سطر ۸ (زهرا بالا): ستون E «اضافه‌کار»: ۱۵۰ مجاز نیست؛ باید بین ۰ و ۱۲۰ باشد.', $rows[5]);
        // A new column with a text value becomes a text column, so «زیاد» is fine.
        $this->assertCount(6, $rows);
    }

    public function test_invisible_marks_around_a_national_code_are_ignored(): void
    {
        $code = $this->validNationalCode();
        $path = $this->csv([
            ['نام', 'نام خانوادگی', 'کد ملی'],
            ['علی', 'رضایی', "\u{FEFF}{$code}\u{200F}"],
        ]);

        $result = app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');

        $this->assertSame(1, $result['created']);
        $this->assertTrue(SheetRow::where('national_code', $code)->exists());
    }

    public function test_line_numbers_match_the_file_even_with_blank_lines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tuka-import-').'.csv';
        file_put_contents($path, "\nنام,نام خانوادگی,کد ملی\n\nعلی,رضایی,{$this->validNationalCode()}\n\n\nسارا,حسینی,111\n");

        try {
            app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $this->assertStringStartsWith('سطر ۷ (سارا حسینی):', $e->errors()['importRows'][0]);
        }
    }

    public function test_header_problems_list_what_was_found(): void
    {
        $path = $this->csv([['نام', 'نام‌خانوادگی', 'کدملی۲', 'حقوق', 'حقوق'], ['الف', 'ب', '1', '2', '3']]);

        try {
            app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $messages = $e->errors()['importFile'];
        }

        $this->assertContains('ستون «حقوق» دو بار در فایل آمده (ستون‌های D و E)؛ یکی را حذف یا عنوانش را عوض کنید.', $messages);
        $this->assertContains('سطر اول فایل (عنوان ستون‌ها) ستون «کد ملی» را ندارد. عنوان قابل قبول: «کد ملی» یا «شماره ملی».', $messages);
        $this->assertContains('عنوان‌هایی که در سطر اول فایل پیدا شد: «نام»، «نام‌خانوادگی»، «کدملی۲»، «حقوق»، «حقوق».', $messages);
    }

    public function test_the_excel_export_can_be_imported_back(): void
    {
        SheetCell::create(['row_id' => $this->rowA->id, 'column_id' => $this->openColumn->id, 'value' => '12', 'version' => 1]);
        $path = app(SheetExporter::class)->toXlsx($this->manager, $this->sheet);
        $columns = SheetColumn::where('sheet_id', $this->sheet->id)->count();

        $result = app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'xlsx');
        @unlink($path);

        $this->assertSame(['mode' => 'full', 'created' => 0, 'updated' => 2, 'columns' => 0], $result);
        $this->assertSame($columns, SheetColumn::where('sheet_id', $this->sheet->id)->count());
        $this->assertSame('12', SheetCell::where('row_id', $this->rowA->id)->where('column_id', $this->openColumn->id)->value('value'));
    }

    public function test_rows_of_a_locked_list_are_not_changed_by_the_import(): void
    {
        $path = $this->csv([
            ['نام', 'نام خانوادگی', 'کد ملی', 'اضافه‌کار'],
            ['نام', 'تازه', $this->rowA->national_code, '99'],
        ]);

        foreach ([Stage::CeoApproved, Stage::Final] as $stage) {
            $this->sheetProject($this->projectA)->update(['stage' => $stage]);
            try {
                app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');
                $this->fail("Import should fail at {$stage->name}.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('در لیست قفل‌شده‌ی پروژه «دماوند» (تایید مدیرعامل یا نهایی) است', $e->errors()['importRows'][0]);
            }
        }
        $this->assertSame($this->rowA->last_name, $this->rowA->fresh()->last_name);
    }
}
