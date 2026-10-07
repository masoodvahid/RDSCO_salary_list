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

        $spA->update(['stage' => Stage::HrApproved]);
        $this->assertTrue($this->access->canEditCell($this->manager, $this->sheet, $this->rowA, $this->openColumn, $spA));

        // From the CEO's approval on, the list is locked, except that HR may still correct it (logged as after
        // approval); taking it back (reopen, rejecting a record) stays with finance.
        foreach ([Stage::CeoApproved, Stage::Final] as $stage) {
            $spA->update(['stage' => $stage]);
            $this->assertFalse($this->access->canEditCell($approver, $this->sheet, $this->rowA, $this->openColumn, $spA), $stage->name);
            $this->assertTrue($this->access->canEditCell($this->manager, $this->sheet, $this->rowA, $this->lockedColumn, $spA), $stage->name);
            $this->assertTrue($this->access->canEditIdentity($this->manager, $spA), $stage->name);
            $this->assertFalse($this->access->canReopen($this->manager, $spA), $stage->name);
            $this->assertFalse($this->access->canReview($this->manager, $spA), $stage->name);
        }
    }

    public function test_viewers_the_ceo_and_finance_never_edit_values(): void
    {
        $viewer = User::factory()->viewer()->create();
        $ceo = User::factory()->approver()->create();
        $finance = User::factory()->finance()->create();
        $spA = $this->sheetProject($this->projectA);

        foreach ([$viewer, $ceo, $finance] as $user) {
            $this->assertFalse($this->access->canEditCell($user, $this->sheet, $this->rowA, $this->openColumn, $spA));
            $this->assertFalse($this->access->canEditIdentity($user, $spA));
            $this->assertFalse($this->access->canImportValues($user, $this->sheet));
            $this->assertFalse($this->access->canSubmit($user, $this->sheet, $spA));
        }
        $this->assertTrue($this->access->canNote($viewer, $this->rowA));
        $this->assertTrue($this->access->canNote($finance, $this->rowA));
    }

    public function test_finance_sees_every_project_and_is_not_the_ceo(): void
    {
        $finance = User::factory()->finance()->create();
        // Even with a stray project row (not possible from the members page) the role decides the scope.
        $finance->syncProjects([$this->projectA->id]);

        $this->assertTrue($finance->isFinance());
        $this->assertFalse($finance->isGlobalApprover());
        $this->assertTrue($finance->hasAllProjects());
        $this->assertSame('همه پروژه‌ها', $finance->scopeLabel());
        $this->assertCount(2, $this->access->visibleRows($finance, $this->sheet)->get());
        $this->assertNull($finance->projects()->first()->pivot->approver_key);
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
        $ceo = User::factory()->approver()->create();
        $finance = User::factory()->finance()->create();
        $spA = $this->sheetProject($this->projectA);

        // stage => [who approves now and into which stage, who reviews records]
        $chain = [
            [Stage::Draft, [[$approverA, Stage::ProjectApproved], [$this->manager, Stage::HrApproved]], [$this->manager]],
            [Stage::ProjectApproved, [[$this->manager, Stage::HrApproved]], [$this->manager]],
            [Stage::HrApproved, [[$ceo, Stage::CeoApproved]], [$this->manager, $ceo]],
            [Stage::CeoApproved, [[$finance, Stage::Final]], [$finance]],
            [Stage::Final, [], []],
        ];
        $everyone = ['approverA' => $approverA, 'approverB' => $approverB, 'manager' => $this->manager, 'ceo' => $ceo, 'finance' => $finance];

        foreach ($chain as [$stage, $approvers, $reviewers]) {
            $spA->update(['stage' => $stage]);
            foreach ($everyone as $name => $user) {
                $expected = collect($approvers)->first(fn ($pair) => $pair[0]->is($user))[1] ?? null;
                $this->assertSame($expected, $this->access->approvalTarget($user, $spA), "{$name} at {$stage->name}");
                $this->assertSame(in_array($user, $reviewers, true), $this->access->canReview($user, $spA), "{$name} reviews at {$stage->name}");
            }
        }
    }
}
