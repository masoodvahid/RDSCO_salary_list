<?php

namespace Tests\Feature;

use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\PersonnelImporter;
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

        $this->assertSame(['created' => 2, 'updated' => 0, 'columns' => 1], $result);
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
            $this->assertCount(2, $e->errors()['importFile']);
        }

        $this->assertSame($before, SheetRow::count());
    }

    public function test_only_managers_import(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $path = $this->csv([['نام', 'نام خانوادگی', 'کد ملی'], ['الف', 'ب', $this->validNationalCode()]]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(PersonnelImporter::class)->import($editor, $this->sheet, $path, 'csv');
    }
}
