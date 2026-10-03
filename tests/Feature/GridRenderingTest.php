<?php

namespace Tests\Feature;

use App\Livewire\Sheets\Grid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/**
 * The payroll table is the heavy part of the grid page; these guard what keeps it fast with many people.
 */
class GridRenderingTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    /** @return array<int, string> row id => data-hash */
    private function rowHashes(string $html): array
    {
        preg_match_all('/data-row-id="(\d+)" data-hash="([0-9a-f]+)"/', $html, $m);

        return array_combine(array_map('intval', $m[1]), $m[2]);
    }

    public function test_dialogs_do_not_re_render_the_table_but_data_changes_do(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);

        foreach ([['openNotes', $this->rowA->id], ['closeModal'], ['openReject', $this->rowA->id], ['closeModal'], ['openColumn', $this->openColumn->id], ['closeModal'], ['openRow']] as $call) {
            $grid->call(...$call);
            $this->assertSame('', $this->gridTable($grid), $call[0].' should leave the table alone.');
        }
        $grid->call('closeModal')->call('approveRow', $this->rowA->id);

        $table = $this->gridTable($grid);
        $this->assertStringContainsString('data-row-id="'.$this->rowA->id.'"', $table);
        $this->assertStringContainsString('aria-pressed="true"', $table);
    }

    public function test_saving_from_a_dialog_refreshes_the_table(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet])
            ->call('openColumn')->set('columnTitle', 'پاداش')->call('saveColumn')->assertHasNoErrors();
        $this->assertStringContainsString('پاداش', $this->gridTable($grid));

        $grid->call('openNotes', $this->rowA->id)->set('newNote', 'لطفاً بررسی شود')->call('addNote')->assertHasNoErrors();
        $this->assertMatchesRegularExpression('/class="note-btn has-notes"[^>]*>.*?۱/s', $this->gridTable($grid));
    }

    public function test_only_the_changed_row_gets_a_new_hash(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);
        $before = $this->rowHashes($grid->html());
        $this->assertCount(2, $before);

        $grid->call('approveRow', $this->rowA->id);
        $after = $this->rowHashes($this->gridTable($grid));

        $this->assertNotSame($before[$this->rowA->id], $after[$this->rowA->id]);
        $this->assertSame($before[$this->rowB->id], $after[$this->rowB->id]);
    }

    public function test_table_actions_bypass_island_scoping_and_the_alpine_setup_stays_fixed(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);
        $html = $grid->html();

        // A wire:click inside the island would only re-render the island (no dialogs, no toolbar).
        $this->assertStringNotContainsString('wire:click="openNotes(', $html);
        $this->assertStringContainsString('x-on:click="$wire.openNotes('.$this->rowA->id.')"', $html);

        // A changing x-data expression makes Alpine run the grid script again on every render.
        $this->assertStringContainsString('x-data="sheetGrid({ poll: 12 })" data-synced-at=', $html);
        $this->travel(5)->seconds();
        $grid->call('approveRow', $this->rowA->id);
        $this->assertStringContainsString('x-data="sheetGrid({ poll: 12 })"', $grid->html());
    }

    public function test_editors_get_inputs_only_where_they_may_write(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $html = Livewire::actingAs($editor)->test(Grid::class, ['sheet' => $this->sheet])->html();

        $this->assertStringContainsString('data-row="'.$this->rowA->id.'" data-col="'.$this->openColumn->id.'"', $html);
        $this->assertStringNotContainsString('data-col="'.$this->lockedColumn->id.'"', $html);
        $this->assertStringNotContainsString('data-row-id="'.$this->rowB->id.'"', $html);
    }
}
