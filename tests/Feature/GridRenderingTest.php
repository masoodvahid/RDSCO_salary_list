<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Livewire\Sheets\Grid;
use App\Models\SheetCell;
use App\Models\User;
use App\Services\ReviewService;
use App\Services\SheetEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
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

    /** @return array<int, string> row id => row HTML sent by the last call for a single-row update */
    private function patchedRows(Testable $grid): array
    {
        $event = collect($grid->effects['dispatches'] ?? [])->firstWhere('name', 'grid-rows');

        return $event['params']['rows'] ?? [];
    }

    public function test_dialogs_skip_the_table_and_single_row_actions_only_send_that_row(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);

        foreach ([['openNotes', $this->rowA->id], ['closeModal'], ['openReject', $this->rowA->id], ['closeModal'], ['openColumn', $this->openColumn->id], ['closeModal'], ['openRow']] as $call) {
            $grid->call(...$call);
            $this->assertSame('', $this->gridTable($grid), $call[0].' should leave the table alone.');
        }

        $grid->call('closeModal')->call('approveRow', $this->rowA->id);
        $this->assertSame('', $this->gridTable($grid));
        $rows = $this->patchedRows($grid);
        $this->assertSame([$this->rowA->id], array_keys($rows));
        $this->assertStringContainsString('aria-pressed="true"', $rows[$this->rowA->id]);

        $grid->call('openNotes', $this->rowA->id)->set('newNote', 'لطفاً بررسی شود')->call('addNote')->assertHasNoErrors();
        $this->assertMatchesRegularExpression('/class="note-btn has-notes"[^>]*>.*?۱/s', $this->patchedRows($grid)[$this->rowA->id]);

        $grid->call('setRowProject', $this->rowA->id, $this->projectB->id);
        $this->assertStringContainsString('<option value="'.$this->projectB->id.'" selected>سپهر</option>', $this->patchedRows($grid)[$this->rowA->id]);

        // Deleting shifts the rows below it: the whole table.
        $grid->call('deleteRow', $this->rowB->id);
        $this->assertStringNotContainsString('data-row-id="'.$this->rowB->id.'"', $this->gridTable($grid));
    }

    public function test_a_row_that_leaves_the_view_or_a_rejection_that_moves_the_stage_renders_the_table(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet])->set('reviewFilter', 'pending');
        $grid->call('approveRow', $this->rowA->id);
        $this->assertSame([], $this->patchedRows($grid));
        $this->assertStringNotContainsString('data-row-id="'.$this->rowA->id.'"', $this->gridTable($grid));
        $this->assertStringContainsString('data-row-id="'.$this->rowB->id.'"', $this->gridTable($grid));

        $grid->set('reviewFilter', '')->call('filterProject', $this->projectB->id)->call('setRowProject', $this->rowB->id, $this->projectA->id);
        $this->assertSame([], $this->patchedRows($grid));
        $this->assertStringNotContainsString('data-row-id="'.$this->rowB->id.'"', $this->gridTable($grid));

        // A manager's rejection sends an approved list back to the project: everyone's rights change.
        $this->sheetProject($this->projectA)->update(['stage' => Stage::ProjectApproved]);
        $grid->call('filterProject', null)->call('openReject', $this->rowA->id)->set('rejectNote', 'اضافه‌کار اشتباه است')->call('confirmReject')->assertHasNoErrors();
        $this->assertSame(Stage::Draft, $this->sheetProject($this->projectA)->stage);
        $this->assertSame([], $this->patchedRows($grid));
        $this->assertStringContainsString('data-row-id="'.$this->rowA->id.'"', $this->gridTable($grid));
    }

    public function test_a_patched_row_is_the_same_html_as_in_the_full_table(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);
        $before = $this->rowHashes($grid->html());
        $this->assertCount(2, $before);

        $grid->call('approveRow', $this->rowA->id);
        $patched = $this->patchedRows($grid)[$this->rowA->id];
        $this->assertNotSame($before[$this->rowA->id], $this->rowHashes($patched)[$this->rowA->id]);

        // A full render (as after a filter or a refresh) gives the very same row, and leaves the other alone.
        $grid->call('$refresh');
        $table = $this->gridTable($grid);
        $this->assertStringContainsString(trim($patched), $table);
        $this->assertSame($before[$this->rowB->id], $this->rowHashes($table)[$this->rowB->id]);
    }

    public function test_sync_patches_rows_others_changed_and_reloads_only_for_structure_changes(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);
        $this->travel(5)->seconds();
        $signature = $grid->instance()->structureSignature();
        $since = now()->toIso8601String();
        $this->travel(5)->seconds();

        // Someone reviews a record: the open grid gets that row, not a reload.
        app(ReviewService::class)->review(User::factory()->manager()->create(), $this->rowB->fresh(), ReviewStatus::Approved);
        $result = $grid->call('changesSince', $since, $signature)->effects['returns'][0];
        $this->assertSame($signature, $result['signature']);
        $this->assertSame([$this->rowB->id], array_keys($result['rows']));
        $this->assertStringContainsString('aria-pressed="true"', $result['rows'][$this->rowB->id]);

        // A note does not touch the row itself, yet its count travels the same way.
        app(ReviewService::class)->addNote(User::factory()->viewer()->create(), $this->rowA->fresh(), 'تایید شد؟');
        $result = $grid->call('changesSince', $since, $signature)->effects['returns'][0];
        $this->assertSame($signature, $result['signature']);
        $this->assertEqualsCanonicalizing([$this->rowA->id, $this->rowB->id], array_keys($result['rows']));
        $this->assertMatchesRegularExpression('/class="note-btn has-notes"[^>]*>.*?۱/s', $result['rows'][$this->rowA->id]);

        // A new person changes which rows the view shows: reload.
        app(SheetEditor::class)->addRow($this->manager, $this->sheet, ['first_name' => 'سارا', 'last_name' => 'نوری', 'national_code' => $this->validNationalCode(), 'project_id' => $this->projectA->id]);
        $result = $grid->call('changesSince', $since, $signature)->effects['returns'][0];
        $this->assertNotSame($signature, $result['signature']);
        $this->assertSame([], $result['rows']);
    }

    public function test_rows_have_no_alpine_directives_and_the_alpine_setup_stays_fixed(): void
    {
        $grid = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet]);
        $html = $grid->html();

        // The rows are handled by sheet-grid.js (event delegation); their tbody is skipped by Alpine.
        $this->assertStringContainsString('<tbody x-ignore>', $html);
        $body = substr($html, strpos($html, '<tbody x-ignore>'), strpos($html, '</tbody>') - strpos($html, '<tbody x-ignore>'));
        $this->assertStringNotContainsString('x-on:', $body);
        $this->assertStringNotContainsString('wire:click', $body);
        $this->assertStringContainsString('data-act="notes"', $body);
        $this->assertStringContainsString('<tr id="v-top" class="v-pad"', $body);

        // A changing x-data expression makes Alpine run the grid script again on every render.
        $this->assertStringContainsString('x-data="sheetGrid({ poll: 12 })" data-synced-at=', $html);
        $this->travel(5)->seconds();
        $grid->call('filterProject', $this->projectA->id);
        $this->assertStringContainsString('x-data="sheetGrid({ poll: 12 })"', $grid->html());
    }

    public function test_totals_and_values_in_the_table(): void
    {
        SheetCell::create(['row_id' => $this->rowA->id, 'column_id' => $this->openColumn->id, 'value' => '1500000', 'version' => 3]);
        SheetCell::create(['row_id' => $this->rowB->id, 'column_id' => $this->openColumn->id, 'value' => '2500000.5', 'version' => 1]);
        SheetCell::create(['row_id' => $this->rowB->id, 'column_id' => $this->textColumn->id, 'value' => 'تطبیق "دستی" <b>', 'version' => 1]);

        $html = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet])->html();

        $this->assertStringContainsString('data-version="3" data-saved="1500000"', $html);
        $this->assertStringContainsString('value="1,500,000"', $html);
        $this->assertStringContainsString('value="تطبیق &quot;دستی&quot; &lt;b&gt;"', $html);
        $this->assertStringContainsString('<span class="cell-text num text-left">4,000,000.5</span>', $html);
    }

    public function test_editors_get_inputs_only_where_they_may_write(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $html = Livewire::actingAs($editor)->test(Grid::class, ['sheet' => $this->sheet])->html();

        $this->assertStringContainsString('data-row="'.$this->rowA->id.'" data-col="'.$this->openColumn->id.'"', $html);
        $this->assertStringNotContainsString('data-col="'.$this->lockedColumn->id.'"', $html);
        $this->assertStringNotContainsString('data-row-id="'.$this->rowB->id.'"', $html);
        $this->assertStringNotContainsString('data-select-row', $html);
        $this->assertStringNotContainsString('data-act="delete"', $html);
    }
}
