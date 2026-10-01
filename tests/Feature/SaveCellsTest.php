<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\ChangeLog;
use App\Models\SheetCell;
use App\Models\User;
use App\Services\SheetEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class SaveCellsTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private SheetEditor $editorService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->editorService = app(SheetEditor::class);
    }

    public function test_editor_saves_normalized_numbers_and_the_change_is_logged(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        $result = $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '۱۲٬۵۰۰', 'version' => 0],
        ]);

        $this->assertCount(1, $result['saved']);
        $this->assertSame('12500', $result['saved'][0]['value']);
        $this->assertSame(1, $result['saved'][0]['version']);
        $this->assertDatabaseHas('sheet_cells', ['row_id' => $this->rowA->id, 'column_id' => $this->openColumn->id, 'value' => '12500']);
        $this->assertSame(1, ChangeLog::where('action', 'cell.update')->count());
    }

    public function test_stale_version_is_reported_as_a_conflict(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $this->editorService->saveCells($this->manager, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '10', 'version' => 0],
        ]);

        $result = $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '20', 'version' => 0],
        ]);

        $this->assertCount(1, $result['conflicts']);
        $this->assertSame('10', SheetCell::where('row_id', $this->rowA->id)->value('value'));
    }

    public function test_locked_columns_other_projects_and_bad_numbers_are_refused(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        $result = $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->lockedColumn->id, 'value' => '1'],
            ['row' => $this->rowB->id, 'column' => $this->openColumn->id, 'value' => '1'],
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => 'abc'],
            ['row' => $this->rowA->id, 'field' => 'national_code', 'value' => '0499370899'],
        ]);

        $this->assertCount(3, $result['denied']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, SheetCell::count());
    }

    public function test_manager_edits_personnel_fields_with_validation(): void
    {
        $result = $this->editorService->saveCells($this->manager, $this->sheet, [
            ['row' => $this->rowA->id, 'field' => 'national_code', 'value' => '123'],
            ['row' => $this->rowA->id, 'field' => 'first_name', 'value' => 'مریم'],
        ]);

        $this->assertCount(1, $result['errors']);
        $this->assertSame('مریم', $this->rowA->fresh()->first_name);
    }

    public function test_fixing_a_rejected_row_sends_it_back_for_review(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $this->rowA->update(['review_status' => ReviewStatus::Rejected]);

        $this->editorService->saveCells($editor, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->textColumn->id, 'value' => 'اصلاح شد'],
        ]);

        $this->assertSame(ReviewStatus::Pending, $this->rowA->fresh()->review_status);
    }

    public function test_only_managers_add_rows_and_columns(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->editorService->addRow($editor, $this->sheet, [
            'first_name' => 'الف', 'last_name' => 'ب', 'national_code' => $this->validNationalCode(), 'project_id' => $this->projectA->id,
        ]);
    }

    public function test_manager_adds_a_row_with_a_unique_valid_national_code(): void
    {
        $code = $this->validNationalCode();
        $row = $this->editorService->addRow($this->manager, $this->sheet, [
            'first_name' => 'علی', 'last_name' => 'رضایی', 'national_code' => $code, 'project_id' => $this->projectA->id,
        ]);
        $this->assertSame($code, $row->national_code);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->editorService->addRow($this->manager, $this->sheet, [
            'first_name' => 'علی', 'last_name' => 'رضایی', 'national_code' => $code,
        ]);
    }
}
