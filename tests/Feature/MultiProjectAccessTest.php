<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Livewire\Sheets\Grid;
use App\Models\Project;
use App\Models\SheetProject;
use App\Models\User;
use App\Services\SheetAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class MultiProjectAccessTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private SheetAccess $access;

    private Project $projectC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->access = app(SheetAccess::class);

        $this->projectC = Project::factory()->create(['name' => 'آتیه']);
        SheetProject::create(['sheet_id' => $this->sheet->id, 'project_id' => $this->projectC->id]);
    }

    public function test_an_editor_of_two_projects_edits_both_and_nothing_else(): void
    {
        $rowC = $this->makeRow($this->projectC);
        $editor = User::factory()->editor($this->projectA, $this->projectB)->create();

        $this->assertFalse($editor->hasAllProjects());
        $this->assertEqualsCanonicalizing([$this->projectA->id, $this->projectB->id], $this->access->projectScope($editor));
        $this->assertTrue($this->access->canEditCell($editor, $this->sheet, $this->rowA, $this->openColumn, $this->sheetProject($this->projectA)));
        $this->assertTrue($this->access->canEditCell($editor, $this->sheet, $this->rowB, $this->openColumn, $this->sheetProject($this->projectB)));
        $this->assertFalse($this->access->canEditCell($editor, $this->sheet, $rowC, $this->openColumn, $this->sheetProject($this->projectC)));
        $this->assertFalse($this->access->canEditCell($editor, $this->sheet, $this->rowA, $this->lockedColumn, $this->sheetProject($this->projectA)));
        $this->assertEqualsCanonicalizing([$this->rowA->id, $this->rowB->id], $this->access->visibleRows($editor, $this->sheet)->pluck('id')->all());
        $this->assertTrue($this->access->canSubmit($editor, $this->sheet, $this->sheetProject($this->projectB)));
        $this->assertFalse($this->access->canSubmit($editor, $this->sheet, $this->sheetProject($this->projectC)));
    }

    public function test_an_approver_of_two_projects_approves_each_of_them(): void
    {
        $approver = User::factory()->approver($this->projectA, $this->projectB)->create();

        $this->assertFalse($approver->isGlobalApprover());
        $this->assertSame(Stage::ProjectApproved, $this->access->approvalTarget($approver, $this->sheetProject($this->projectA)));
        $this->assertSame(Stage::ProjectApproved, $this->access->approvalTarget($approver, $this->sheetProject($this->projectB)));
        $this->assertNull($this->access->approvalTarget($approver, $this->sheetProject($this->projectC)));
    }

    public function test_an_editor_without_projects_sees_nothing(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->assertFalse($editor->hasAllProjects());
        $this->assertSame([], $this->access->projectScope($editor));
        $this->assertSame(0, $this->access->visibleRows($editor, $this->sheet)->count());
        $this->assertSame('بدون پروژه', $editor->scopeLabel());
    }

    public function test_grid_lets_a_multi_project_member_switch_between_own_projects_only(): void
    {
        $this->makeRow($this->projectC);
        $editor = User::factory()->editor($this->projectA, $this->projectB)->create();

        $grid = Livewire::actingAs($editor)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->assertSet('projectFilter', null)
            ->assertSee('همه‌ی پروژه‌های من')
            ->assertSee('برای ارسال یا تایید، پروژه را انتخاب کنید.');
        $this->assertEqualsCanonicalizing([$this->rowA->id, $this->rowB->id], $grid->instance()->rows()->pluck('id')->all());

        $grid->call('filterProject', $this->projectC->id)->assertSet('projectFilter', null)
            ->call('filterProject', 'none')->assertSet('projectFilter', null)
            ->call('filterProject', $this->projectB->id)->assertSet('projectFilter', $this->projectB->id)
            ->assertSee('ارسال برای تایید');
        $this->assertSame([$this->rowB->id], $grid->instance()->rows()->pluck('id')->all());
    }

    public function test_grid_pins_a_single_project_member_to_their_project(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        Livewire::actingAs($editor)
            ->withQueryParams(['project' => $this->projectB->id])
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->assertSet('projectFilter', $this->projectA->id)
            ->assertSee('شما فقط پرسنل پروژه')
            ->assertDontSee('همه‌ی پروژه‌های من');
    }

    public function test_header_and_members_list_show_every_project_of_a_member(): void
    {
        $viewer = User::factory()->viewer($this->projectA, $this->projectB, $this->projectC)->create();
        $this->assertSame('آتیه، دماوند، سپهر', $viewer->scopeLabel());

        $fourth = Project::factory()->create(['name' => 'البرز']);
        $viewer->syncProjects([...$viewer->projectIds(), $fourth->id]);
        $this->assertSame('آتیه، البرز و ۲ پروژه‌ی دیگر', $viewer->fresh()->scopeLabel());

        $this->actingAs($this->manager)->get(route('members'))->assertOk()->assertSee('آتیه، البرز و ۲ پروژه‌ی دیگر');
    }
}
