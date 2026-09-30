<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Models\User;
use App\Services\SheetAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class AccessRulesTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private SheetAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->access = app(SheetAccess::class);
    }

    public function test_editor_edits_only_unlocked_cells_of_own_project(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $spA = $this->sheetProject($this->projectA);
        $spB = $this->sheetProject($this->projectB);

        $this->assertTrue($this->access->canEditCell($editor, $this->sheet, $this->rowA, $this->openColumn, $spA));
        $this->assertFalse($this->access->canEditCell($editor, $this->sheet, $this->rowA, $this->lockedColumn, $spA));
        $this->assertFalse($this->access->canEditCell($editor, $this->sheet, $this->rowB, $this->openColumn, $spB));
        $this->assertFalse($this->access->canEditIdentity($editor, $spA));
    }

    public function test_project_editing_closes_after_the_deadline_but_not_for_the_manager(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $spA = $this->sheetProject($this->projectA);
        $later = $this->sheet->deadline_at->copy()->addMinute();

        $this->assertFalse($this->access->canEditCell($editor, $this->sheet, $this->rowA, $this->openColumn, $spA, $later));
        $this->assertTrue($this->access->canEditCell($this->manager, $this->sheet, $this->rowA, $this->lockedColumn, $spA, $later));
    }

    public function test_each_approval_locks_the_roles_below_it(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $approver = User::factory()->approver($this->projectA)->create();
        $spA = $this->sheetProject($this->projectA);

        $spA->update(['stage' => Stage::ProjectApproved]);
        $this->assertFalse($this->access->canEditCell($editor, $this->sheet, $this->rowA, $this->openColumn, $spA));
        $this->assertFalse($this->access->canEditCell($approver, $this->sheet, $this->rowA, $this->openColumn, $spA));
        $this->assertTrue($this->access->canEditCell($this->manager, $this->sheet, $this->rowA, $this->openColumn, $spA));

        $spA->update(['stage' => Stage::Final]);
        $this->assertFalse($this->access->canEditCell($this->manager, $this->sheet, $this->rowA, $this->openColumn, $spA));
    }

    public function test_viewers_and_finance_never_edit_values(): void
    {
        $viewer = User::factory()->viewer()->create();
        $finance = User::factory()->approver()->create();
        $spA = $this->sheetProject($this->projectA);

        $this->assertFalse($this->access->canEditCell($viewer, $this->sheet, $this->rowA, $this->openColumn, $spA));
        $this->assertFalse($this->access->canEditCell($finance, $this->sheet, $this->rowA, $this->openColumn, $spA));
        $this->assertTrue($this->access->canNote($viewer, $this->rowA));
    }

    public function test_project_scoped_users_only_see_their_rows(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();
        $viewerB = User::factory()->viewer($this->projectB)->create();
        $ceo = User::factory()->viewer()->create();

        $this->assertSame([$this->rowA->id], $this->access->visibleRows($editor, $this->sheet)->pluck('id')->all());
        $this->assertSame([$this->rowB->id], $this->access->visibleRows($viewerB, $this->sheet)->pluck('id')->all());
        $this->assertCount(2, $this->access->visibleRows($ceo, $this->sheet)->get());
        $this->assertFalse($this->access->canNote($editor, $this->rowB));
    }

    public function test_approval_targets_follow_the_chain(): void
    {
        $approverA = User::factory()->approver($this->projectA)->create();
        $approverB = User::factory()->approver($this->projectB)->create();
        $finance = User::factory()->approver()->create();
        $spA = $this->sheetProject($this->projectA);

        $this->assertSame(Stage::ProjectApproved, $this->access->approvalTarget($approverA, $spA));
        $this->assertNull($this->access->approvalTarget($approverB, $spA));
        $this->assertSame(Stage::HrApproved, $this->access->approvalTarget($this->manager, $spA));
        $this->assertNull($this->access->approvalTarget($finance, $spA));

        $spA->update(['stage' => Stage::HrApproved]);
        $this->assertSame(Stage::Final, $this->access->approvalTarget($finance, $spA));
        $this->assertNull($this->access->approvalTarget($this->manager, $spA));
        $this->assertTrue($this->access->canReview($finance, $spA));
    }
}
