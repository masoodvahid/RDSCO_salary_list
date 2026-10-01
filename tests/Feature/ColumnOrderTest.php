<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Livewire\Sheets\Grid;
use App\Models\ChangeLog;
use App\Models\SheetColumn;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\SheetEditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class ColumnOrderTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    private function order(): array
    {
        return SheetColumn::where('sheet_id', $this->sheet->id)->orderBy('position')->pluck('id')->all();
    }

    public function test_manager_reorders_columns_by_drag_and_drop(): void
    {
        $new = [$this->textColumn->id, $this->openColumn->id, $this->lockedColumn->id];

        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('reorderColumns', $new)
            ->assertHasNoErrors();

        $this->assertSame($new, $this->order());
        $this->assertSame($new, ChangeLog::where('action', 'column.reorder')->firstOrFail()->meta['order']);
    }

    public function test_order_must_list_exactly_the_sheet_columns(): void
    {
        $editor = app(SheetEditor::class);
        $before = $this->order();

        foreach ([[$this->openColumn->id, $this->textColumn->id], [...$before, 999999], [$before[0], $before[0], $before[1]]] as $ids) {
            try {
                $editor->reorderColumns($this->manager, $this->sheet, $ids);
                $this->fail('Order '.json_encode($ids).' should be rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('column', $e->errors());
            }
        }

        $this->assertSame($before, $this->order());
    }

    public function test_only_managers_reorder_columns(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        $this->expectException(AuthorizationException::class);
        app(SheetEditor::class)->reorderColumns($editor, $this->sheet, array_reverse($this->order()));
    }

    public function test_reordering_does_not_change_signed_data(): void
    {
        $sheetProject = $this->sheetProject($this->projectA);
        $sheetProject->update(['stage' => Stage::HrApproved]);
        $hash = app(ApprovalService::class)->dataHash($sheetProject);

        app(SheetEditor::class)->reorderColumns($this->manager, $this->sheet, array_reverse($this->order()));

        $this->assertSame($hash, app(ApprovalService::class)->dataHash($sheetProject->fresh()));
    }
}
