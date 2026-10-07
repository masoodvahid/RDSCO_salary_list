<?php

namespace Tests\Feature;

use App\Livewire\Sheets\Grid;
use App\Models\SheetColumn;
use App\Models\User;
use App\Services\SheetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/** Column widths are each user's own view setting, saved when they drag a column edge. */
class ColumnWidthsTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    public function test_a_dragged_width_is_saved_for_the_user_and_rendered_in_the_header(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);
        $html = $grid->html();
        $this->assertStringContainsString('style="--w-first: 120px"', $html);
        $this->assertStringContainsString('<th id="h-first_name" class="sticky-2 px-2" style="width:120px" data-default-width="120">', $html);
        $this->assertStringContainsString('data-col-resize="'.$this->openColumn->id.'"', $html);

        $grid->call('saveColumnWidth', 'first_name', 150)
            ->call('saveColumnWidth', (string) $this->openColumn->id, 210.4)
            ->call('saveColumnWidth', 'project', 90);
        // Saving re-renders nothing.
        $this->assertSame('', $this->gridTable($grid));

        $this->assertSame(['f:first_name' => 150, 'c:اضافه‌کار' => 210, 'f:project' => 90], $this->manager->fresh()->gridWidths());

        $html = Livewire::actingAs($this->manager->fresh())->test(Grid::class, ['sheet' => $this->sheet])->html();
        $this->assertStringContainsString('style="--w-first: 150px"', $html);
        $this->assertStringContainsString('<th id="h-first_name" class="sticky-2 px-2" style="width:150px"', $html);
        $this->assertStringContainsString('<th id="h'.$this->openColumn->id.'" class="px-2" style="width:210px" data-default-width="130"', $html);
        $this->assertStringContainsString('style="width:90px" data-default-width="140"', $html);
    }

    public function test_widths_are_clamped_and_unknown_columns_ignored(): void
    {
        $otherSheet = app(SheetBuilder::class)->create($this->manager, 1405, 7);
        $foreign = SheetColumn::create(['sheet_id' => $otherSheet->id, 'title' => 'پاداش', 'type' => 'number', 'position' => 1]);
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);

        $grid->call('saveColumnWidth', 'first_name', 5000)
            ->call('saveColumnWidth', 'national_code', 5000)
            ->call('saveColumnWidth', (string) $this->textColumn->id, 3)
            ->call('saveColumnWidth', 'role', 200)
            ->call('saveColumnWidth', (string) $foreign->id, 200)
            ->call('saveColumnWidth', 'last_name', 'wide');

        $this->assertSame(['f:first_name' => 320, 'f:national_code' => 600, 'c:توضیحات' => 56], $this->manager->fresh()->gridWidths());

        // Double-click on the handle: back to the default width.
        $grid->call('saveColumnWidth', 'first_name', null);
        $this->assertSame(['f:national_code' => 600, 'c:توضیحات' => 56], $this->manager->fresh()->gridWidths());
    }

    public function test_each_user_has_their_own_widths_and_they_carry_over_to_the_next_month(): void
    {
        $viewer = User::factory()->viewer()->create();
        Livewire::actingAs($viewer)->test(Grid::class, ['sheet' => $this->sheet])->call('saveColumnWidth', (string) $this->openColumn->id, 260);

        $this->assertSame(['c:اضافه‌کار' => 260], $viewer->fresh()->gridWidths());
        $this->assertSame([], $this->manager->fresh()->gridWidths());

        // Next month's list has its own columns; the same title gets the same width.
        $next = app(SheetBuilder::class)->create($this->manager, 1405, 7);
        $column = SheetColumn::create(['sheet_id' => $next->id, 'title' => 'اضافه‌کار', 'type' => 'number', 'position' => 1]);
        $html = Livewire::actingAs($viewer->fresh())->test(Grid::class, ['sheet' => $next])->html();
        $this->assertStringContainsString('<th id="h'.$column->id.'" class="px-2" style="width:260px"', $html);
    }

    public function test_only_the_most_recent_widths_are_kept(): void
    {
        foreach (range(1, 205) as $i) {
            $this->manager->rememberGridWidth("c:ستون {$i}", 100 + $i);
        }
        $this->manager->rememberGridWidth('c:ستون 3', 99); // touched again: now among the newest

        $widths = $this->manager->fresh()->gridWidths();
        $this->assertCount(200, $widths);
        $this->assertArrayNotHasKey('c:ستون 1', $widths);
        $this->assertSame(99, $widths['c:ستون 3']);
        $this->assertSame(305, $widths['c:ستون 205']);
    }
}
