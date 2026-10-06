<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Stage;
use App\Livewire\Dashboard;
use App\Livewire\Members;
use App\Livewire\Sheets\Grid;
use App\Models\Project;
use App\Models\SheetCell;
use App\Models\User;
use App\Services\SheetEditor;
use App\Services\SheetLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/**
 * The CEO's approval sits where the final approval used to be (stage 3) and finance approves after it (4).
 * From the CEO's approval on, a list is locked exactly as a final list was before.
 */
class CeoStepTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    public function test_the_stored_values_keep_their_meaning(): void
    {
        $this->assertSame([0, 1, 2, 3, 4], array_map(fn (Stage $s) => $s->value, Stage::cases()));
        $this->assertSame(Stage::CeoApproved, Stage::from(3));
        $this->assertSame('تایید مدیرعامل', Stage::from(3)->label());
        $this->assertSame('تایید مالی', Stage::from(4)->actionLabel());
        $this->assertSame([3, 4], Stage::lockedValues());
        $this->assertFalse(Stage::HrApproved->isLocked());
    }

    /** @param  callable(): mixed  $attempt */
    private function assertRefused(callable $attempt, string $what, ?string $message = null): void
    {
        try {
            $attempt();
            $this->fail("{$what} should be refused.");
        } catch (ValidationException $e) {
            if ($message !== null) {
                $this->assertStringContainsString($message, collect($e->errors())->flatten()->join(' '), $what);
            }
        }
    }

    public function test_a_ceo_approved_list_is_locked_for_the_manager(): void
    {
        $this->sheetProject($this->projectA)->update(['stage' => Stage::CeoApproved]);
        $editor = app(SheetEditor::class);

        $this->assertRefused(fn () => $editor->addRow($this->manager, $this->sheet, [
            'first_name' => 'سارا', 'last_name' => 'نوری', 'national_code' => $this->validNationalCode(), 'project_id' => $this->projectA->id,
        ]), 'Adding a person', 'تایید مدیرعامل گرفته و قفل است');
        $this->assertRefused(fn () => $editor->deleteRow($this->manager, $this->rowA), 'Deleting a person');
        $this->assertRefused(fn () => $editor->setRowProject($this->manager, $this->rowB, $this->projectA->id), 'Moving a person in');
        $this->assertRefused(fn () => $editor->setRowProject($this->manager, $this->rowA, $this->projectB->id), 'Moving a person out');
        $this->assertRefused(fn () => $editor->addColumn($this->manager, $this->sheet, 'پاداش', 'number', false), 'Adding a column', 'پروژه‌ی قفل‌شده');
        $this->assertRefused(fn () => $editor->updateColumn($this->manager, $this->openColumn, 'اضافه‌کار ساعتی', 'number', false), 'Renaming a column');

        $result = $editor->saveCells($this->manager, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->lockedColumn->id, 'value' => '5'],
            ['row' => $this->rowA->id, 'field' => 'last_name', 'value' => 'تازه'],
        ]);
        $this->assertCount(2, $result['denied']);
        $this->assertSame(0, SheetCell::where('row_id', $this->rowA->id)->count());

        $this->assertStringContainsString('پروژه‌ی قفل‌شده (تایید مدیرعامل یا نهایی)', app(SheetLifecycle::class)->moveBlocker($this->sheet, 1405, 4));

        // The other project of the month is not locked.
        $editor->setRowProject($this->manager, $this->rowB, null);
        $this->assertNull($this->rowB->fresh()->project_id);
    }

    public function test_the_grid_shows_finance_its_step_and_keeps_the_rows_locked(): void
    {
        $this->sheetProject($this->projectA)->update(['stage' => Stage::CeoApproved]);
        $finance = User::factory()->finance()->create();

        $grid = Livewire::actingAs($finance)->test(Grid::class, ['sheet' => $this->sheet])->call('filterProject', $this->projectA->id);
        $grid->assertSee('تایید مدیرعامل')->assertSee('تایید مالی با کد پیامکی');
        $table = $this->gridTable($grid);
        $this->assertStringContainsString('data-act="approve" aria-label="تایید رکورد', $table);
        $this->assertStringNotContainsString('data-cell data-row="'.$this->rowA->id.'"', $table);

        // The manager sees the row locked, and cannot pick the project for new rows.
        $html = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet])->html();
        $this->assertStringContainsString('title="لیست این پروژه قفل است (تایید مدیرعامل یا نهایی)"><input type="checkbox" data-select-row="'.$this->rowA->id.'" disabled', $html);
        $this->assertStringContainsString('<option value="'.$this->projectA->id.'" disabled>دماوند</option>', $html);
        $this->assertStringContainsString('data-select-row="'.$this->rowB->id.'" aria-label', $html);
    }

    public function test_finance_is_invited_without_projects(): void
    {
        $project = Project::factory()->create();

        $page = Livewire::actingAs($this->manager)
            ->test(Members::class)
            ->set('role', 'finance')
            ->assertSee('مدیر مالی به همه پروژه‌ها دسترسی دارد.')
            ->set('name', 'نگار مالی')
            ->set('mobile', '09121112233')
            ->set('scope', 'some')
            ->set('projectIds', [(string) $project->id])
            ->call('invite')
            ->assertHasNoErrors();

        $finance = User::where('mobile', '09121112233')->firstOrFail();
        $this->assertSame(Role::Finance, $finance->role);
        $this->assertSame([], $finance->projectIds());
        $this->assertSame('همه پروژه‌ها', $finance->scopeLabel());
        $page->assertSee('مدیر مالی');

        // A project approver who becomes finance gives up the project (and its approver slot).
        $approver = User::factory()->approver($project)->create();
        $page->call('startEdit', $approver->id)->set('editRole', 'finance')->call('saveEdit')->assertHasNoErrors();
        $this->assertSame(Role::Finance, $approver->fresh()->role);
        $this->assertSame([], $approver->fresh()->projectIds());
        User::factory()->approver($project)->create(); // the slot is free again
    }

    public function test_the_dashboard_counts_five_stages(): void
    {
        $this->sheetProject($this->projectA)->update(['stage' => Stage::CeoApproved]);

        $html = Livewire::actingAs($this->manager)->test(Dashboard::class)->html();

        foreach (Stage::cases() as $stage) {
            $this->assertStringContainsString($stage->label(), $html);
        }
        $this->assertStringContainsString('lg:grid-cols-5', $html);
    }
}
