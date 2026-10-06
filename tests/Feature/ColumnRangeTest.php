<?php

namespace Tests\Feature;

use App\Livewire\Sheets\Grid;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\User;
use App\Services\PersonnelImporter;
use App\Services\SheetBuilder;
use App\Services\SheetEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class ColumnRangeTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private SheetEditor $editorService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->editorService = app(SheetEditor::class);
    }

    public function test_range_is_normalized_and_empty_means_no_limit(): void
    {
        $column = $this->editorService->addColumn($this->manager, $this->sheet, 'کارکرد', 'number', false, '۰', '۳۱');
        $this->assertSame(['0', '31'], [$column->min_value, $column->max_value]);
        $this->assertSame('بین ۰ و ۳۱', $column->rangeLabel());

        $open = $this->editorService->addColumn($this->manager, $this->sheet, 'پاداش', 'number', false, '', null);
        $this->assertFalse($open->hasRange());

        $text = $this->editorService->addColumn($this->manager, $this->sheet, 'شرح', 'text', false, '1', '5');
        $this->assertNull($text->min_value);
        $this->assertNull($text->max_value);
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        foreach ([['10', '5', 'columnMax'], ['abc', '', 'columnMin'], ['', '1x', 'columnMax']] as $i => [$min, $max, $field]) {
            try {
                $this->editorService->addColumn($this->manager, $this->sheet, 'ستون '.$i, 'number', false, $min, $max);
                $this->fail("Range {$min}..{$max} should be rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_values_outside_the_range_are_refused_when_saving(): void
    {
        $this->editorService->updateColumn($this->manager, $this->openColumn, $this->openColumn->title, 'number', false, '0', '120');
        $editor = User::factory()->editor($this->projectA)->create();

        $result = $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '121', 'version' => 0],
        ]);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('بین ۰ و ۱۲۰', $result['errors'][0]['message']);
        $this->assertSame(0, SheetCell::count());

        $result = $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '۱۲۰', 'version' => 0],
        ]);
        $this->assertCount(1, $result['saved']);

        // Clearing a cell is always allowed.
        $result = $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '', 'version' => 1],
        ]);
        $this->assertCount(1, $result['saved']);
        $this->assertNull(SheetCell::first()->value);
    }

    public function test_one_sided_range_of_whole_numbers(): void
    {
        $column = $this->editorService->addColumn($this->manager, $this->sheet, 'نرخ', 'number', false, '-2', null);

        $this->assertTrue($column->isOutOfRange('-3'));
        $this->assertFalse($column->isOutOfRange('-2'));
        $this->assertFalse($column->isOutOfRange('999999999999999999999'));
        $this->assertSame('حداقل -۲', $column->rangeLabel());

        // Number columns hold whole numbers, so a range with a fraction is refused ("10.0" is 10).
        try {
            $this->editorService->addColumn($this->manager, $this->sheet, 'ضریب', 'number', false, '-2.5', null);
            $this->fail('A fractional minimum should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('عدد صحیح', $e->errors()['columnMin'][0]);
        }
        $this->assertSame('10', $this->editorService->addColumn($this->manager, $this->sheet, 'سقف', 'number', false, null, '10.0')->max_value);

        // A range saved before (with a fraction) keeps working as it was.
        $old = SheetColumn::create(['sheet_id' => $this->sheet->id, 'title' => 'قدیمی', 'type' => 'number', 'min_value' => '-2.5', 'position' => 9]);
        $this->assertTrue($old->isOutOfRange('-3'));
        $this->assertFalse($old->isOutOfRange('-2'));
    }

    public function test_narrowing_a_range_keeps_existing_values_and_counts_them(): void
    {
        foreach ([[$this->rowA, '50'], [$this->rowB, '200']] as [$row, $value]) {
            SheetCell::create(['row_id' => $row->id, 'column_id' => $this->openColumn->id, 'value' => $value, 'version' => 1]);
        }

        $outside = $this->editorService->updateColumn($this->manager, $this->openColumn, $this->openColumn->title, 'number', false, null, '100');

        $this->assertSame(1, $outside);
        $this->assertSame(2, SheetCell::where('column_id', $this->openColumn->id)->count());
    }

    public function test_import_respects_ranges_and_copy_keeps_them(): void
    {
        $this->openColumn->update(['min_value' => '0', 'max_value' => '120']);
        $path = tempnam(sys_get_temp_dir(), 'tuka-range-').'.csv';
        file_put_contents($path, "نام,نام خانوادگی,کد ملی,اضافه‌کار\nعلی,رضایی,{$this->validNationalCode()},500\n");

        try {
            app(PersonnelImporter::class)->import($this->manager, $this->sheet, $path, 'csv');
            $this->fail('Import should fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('بین ۰ و ۱۲۰', $e->errors()['importRows'][0]);
        }

        $next = app(SheetBuilder::class)->create($this->manager, 1405, 7, $this->sheet);
        $copied = SheetColumn::where('sheet_id', $next->id)->where('title', $this->openColumn->title)->firstOrFail();
        $this->assertSame(['0', '120'], [$copied->min_value, $copied->max_value]);
    }

    public function test_grid_endpoint_refuses_out_of_range_values(): void
    {
        $this->openColumn->update(['min_value' => '0', 'max_value' => '31']);
        $editor = User::factory()->editor($this->projectA)->create();

        Livewire::actingAs($editor)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('saveCells', [['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '45', 'version' => 0]])
            ->call('saveCells', [['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '۴۵', 'version' => 0]])
            ->call('saveCells', [['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '-1', 'version' => 0]]);

        $this->assertSame(0, SheetCell::count());
        // The grid script reads the same rule from the column header.
        $html = Livewire::actingAs($editor)->test(Grid::class, ['sheet' => $this->sheet])->html();
        $this->assertStringContainsString('data-max="31"', $html);
        $this->assertStringContainsString('data-range-message="'.e($this->openColumn->fresh()->rangeMessage()).'"', $html);
    }

    public function test_column_dialog_saves_a_range(): void
    {
        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('openColumn', $this->openColumn->id)
            ->set('columnMin', '۰')
            ->set('columnMax', '۱۲۰')
            ->call('saveColumn')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $this->assertSame(['0', '120'], [$this->openColumn->fresh()->min_value, $this->openColumn->fresh()->max_value]);
    }
}
