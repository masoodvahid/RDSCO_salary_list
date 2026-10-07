<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Livewire\Sheets\Grid;
use App\Models\ChangeLog;
use App\Models\Note;
use App\Models\SheetCell;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\SheetEditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class BulkRowsTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    public function test_manager_deletes_several_rows_with_their_values_and_notes(): void
    {
        $rows = collect(range(1, 6))->map(fn () => $this->makeRow($this->projectA));
        SheetCell::create(['row_id' => $rows[0]->id, 'column_id' => $this->openColumn->id, 'value' => '5', 'version' => 1]);
        Note::create(['sheet_id' => $this->sheet->id, 'row_id' => $rows[1]->id, 'user_id' => $this->manager->id, 'body' => 'x']);

        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('deleteRows', $rows->pluck('id')->all())
            ->assertReturned(true)
            ->assertHasNoErrors();

        $this->assertSame(0, SheetRow::whereIn('id', $rows->pluck('id'))->count());
        $this->assertSame(0, SheetCell::where('row_id', $rows[0]->id)->count());
        $this->assertSame(0, Note::count());
        $this->assertSame(6, ChangeLog::where('action', 'row.delete')->count());
        $this->assertTrue(SheetRow::whereKey($this->rowA->id)->exists());
    }

    public function test_rows_of_a_locked_project_block_the_whole_delete(): void
    {
        // Locked from the CEO's approval on (Final included).
        $this->sheetProject($this->projectB)->update(['stage' => Stage::CeoApproved]);

        try {
            app(SheetEditor::class)->deleteRows($this->manager, $this->sheet, [$this->rowA->id, $this->rowB->id]);
            $this->fail('Delete should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows', $e->errors());
        }

        $this->assertSame(2, SheetRow::whereIn('id', [$this->rowA->id, $this->rowB->id])->count());
    }

    public function test_rows_of_other_sheets_are_ignored_and_editors_cannot_bulk_delete(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        $this->assertSame(1, app(SheetEditor::class)->deleteRows($this->manager, $this->sheet, [$this->rowA->id, 999999]));

        $this->expectException(AuthorizationException::class);
        app(SheetEditor::class)->deleteRows($editor, $this->sheet, [$this->rowB->id]);
    }

    public function test_manager_sets_one_project_for_several_rows(): void
    {
        $unassigned = [$this->makeRow(null), $this->makeRow(null), $this->makeRow($this->projectA)];
        $ids = collect($unassigned)->pluck('id')->all();

        $changed = app(SheetEditor::class)->setRowsProject($this->manager, $this->sheet, $ids, $this->projectB->id);

        $this->assertSame(3, $changed);
        $this->assertSame(3, SheetRow::whereIn('id', $ids)->where('project_id', $this->projectB->id)->count());
        $this->assertSame(3, ChangeLog::where('action', 'row.project')->count());

        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('setRowsProject', $ids, null)
            ->assertReturned(true);
        $this->assertSame(3, SheetRow::whereIn('id', $ids)->whereNull('project_id')->count());

        $this->sheetProject($this->projectA)->update(['stage' => Stage::CeoApproved]);
        $this->expectException(ValidationException::class);
        app(SheetEditor::class)->setRowsProject($this->manager, $this->sheet, $ids, $this->projectA->id);
    }
}
