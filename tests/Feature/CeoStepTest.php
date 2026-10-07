<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Stage;
use App\Livewire\Dashboard;
use App\Livewire\Members;
use App\Livewire\Sheets\Grid;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/**
 * The CEO's approval sits where the final approval used to be (stage 3) and finance approves after it (4).
 * From the CEO's approval on, a list is locked as a final list was before, except for the manager (HR): see
 * ManagerEditsAfterApprovalTest.
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

    public function test_the_grid_shows_finance_its_step_and_keeps_the_rows_locked_but_for_the_manager(): void
    {
        $this->sheetProject($this->projectA)->update(['stage' => Stage::CeoApproved]);
        $finance = User::factory()->finance()->create();

        $grid = Livewire::actingAs($finance)->test(Grid::class, ['sheet' => $this->sheet])->call('filterProject', $this->projectA->id);
        $grid->assertSee('تایید مدیرعامل')->assertSee('تایید مالی با کد پیامکی');
        $table = $this->gridTable($grid);
        $this->assertStringContainsString('data-act="approve" aria-label="تایید رکورد', $table);
        $this->assertStringNotContainsString('data-cell data-row="'.$this->rowA->id.'"', $table);

        // The manager may still change it, and is told how that is recorded.
        $html = Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet])->call('filterProject', $this->projectA->id)->html();
        $this->assertStringContainsString('لیست دماوند «تایید مدیرعامل» گرفته است.', $html);
        $this->assertStringContainsString('با برچسب «بعد از تایید» در روند تایید لیست و فعالیت‌ها ثبت می‌شود', $html);
        $table = $this->gridTable(Livewire::actingAs($this->manager)->test(Grid::class, ['sheet' => $this->sheet])->call('$refresh'));
        $this->assertStringContainsString('<input type="checkbox" data-select-row="'.$this->rowA->id.'" aria-label', $table);
        $this->assertStringContainsString('data-row="'.$this->rowA->id.'" data-col="'.$this->lockedColumn->id.'"', $table);
        $this->assertStringContainsString('<option value="'.$this->projectA->id.'">دماوند</option>', $table);

        // Nobody else gets that notice.
        Livewire::actingAs($finance)->test(Grid::class, ['sheet' => $this->sheet])->call('filterProject', $this->projectA->id)->assertDontSee('شما به‌عنوان مدیر');
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

        $dashboard = Livewire::actingAs($this->manager)->test(Dashboard::class);

        $counts = $dashboard->viewData('stageCounts');
        $this->assertSame([0 => 1, 1 => 0, 2 => 0, 3 => 1, 4 => 0], $counts);
        $html = $dashboard->html();
        $this->assertSame(5, substr_count($html, '<li class="card relative overflow-hidden px-4 pt-4 pb-3.5">'));
        foreach (Stage::cases() as $stage) {
            $this->assertStringContainsString($stage->label(), $html);
        }
    }
}
