<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Models\Approval;
use App\Models\ChangeLog;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ListHistory;
use App\Services\SheetEditor;
use App\Services\SheetLifecycle;
use App\Services\UserActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/**
 * HR (the manager) may change a list at any stage, even after the CEO's and finance's approval. Each such change
 * is logged as made after approval, appears in the list's approval timeline and the member's activity, and the
 * signatures show that the data changed after them.
 */
class ManagerEditsAfterApprovalTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private ApprovalService $approvals;

    private SheetEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->approvals = app(ApprovalService::class);
        $this->editor = app(SheetEditor::class);
    }

    /** The real chain up to the final approval of project A. */
    private function finalizeProjectA(): void
    {
        foreach ([$this->manager, User::factory()->approver()->create(), User::factory()->finance()->create()] as $user) {
            $challenge = $this->approvals->start($user, $this->sheetProject($this->projectA));
            $this->approvals->confirm($user, $challenge, $this->sms->lastCodeFor($user->mobile));
        }
        $this->assertSame(Stage::Final, $this->sheetProject($this->projectA)->stage);
    }

    /** @return array<int, int>|null */
    private function mark(string $action, ?int $rowId = null): ?array
    {
        return ChangeLog::where('action', $action)->when($rowId, fn ($q) => $q->where('row_id', $rowId))->latest('id')->firstOrFail()->meta['after_approval'] ?? null;
    }

    public function test_the_manager_changes_a_final_list_and_every_change_is_logged_as_after_approval(): void
    {
        $this->finalizeProjectA();
        $final = [$this->projectA->id => Stage::Final->value];

        $result = $this->editor->saveCells($this->manager, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->lockedColumn->id, 'value' => '150000000', 'version' => 0],
            ['row' => $this->rowA->id, 'field' => 'last_name', 'value' => 'کریمی'],
        ]);
        $this->assertSame([], $result['denied']);
        $this->assertCount(2, $result['saved']);
        $this->assertSame($final, $this->mark('cell.update'));
        $this->assertSame($final, $this->mark('row.update'));

        $added = $this->editor->addRow($this->manager, $this->sheet, ['first_name' => 'سارا', 'last_name' => 'نوری', 'national_code' => $this->validNationalCode(), 'project_id' => $this->projectA->id]);
        $this->assertSame($final, $this->mark('row.create'));
        $this->editor->setRowProject($this->manager, $this->rowB, $this->projectA->id); // from an open list into the final one
        $this->assertSame($final, $this->mark('row.project'));
        $this->editor->addColumn($this->manager, $this->sheet, 'پاداش', 'number', false);
        $this->assertSame($final, $this->mark('column.create'));
        $this->editor->updateColumn($this->manager, $this->openColumn, 'اضافه‌کار (ساعت)', 'number', false);
        $this->assertSame($final, $this->mark('column.update'));
        $this->editor->deleteRow($this->manager, $added);
        $this->assertSame($final, $this->mark('row.delete'));
        $this->assertNull(SheetRow::find($added->id));

        // The signatures stay, and show the data changed after them; the month still cannot move.
        $sp = $this->sheetProject($this->projectA);
        $hash = $this->approvals->dataHash($sp);
        $this->assertSame(3, Approval::whereNull('revoked_at')->count());
        $this->assertTrue(Approval::whereNull('revoked_at')->get()->every(fn (Approval $a) => $this->approvals->changedSince($a, $hash)));
        $this->assertNotNull(app(SheetLifecycle::class)->moveBlocker($this->sheet, 1405, 4));

        // The approval timeline ends with one line for this sitting, with a few examples.
        $timeline = app(ListHistory::class)->timelines($this->sheet, collect([$sp]), [$sp->id => $hash])[$sp->id];
        $last = end($timeline);
        $this->assertSame('بعد از «تایید مالی · نهایی» لیست را تغییر داد', $last['text']);
        $this->assertSame($this->manager->nameWithTitle(), $last['by']);
        $this->assertStringContainsString('«حقوق پایه» نفر کریمی: خالی ← ۱۵۰٬۰۰۰٬۰۰۰', $last['detail']);
        $this->assertStringContainsString('و ۴ تغییر دیگر', $last['detail']);
        $this->assertTrue(collect($timeline)->where('text', 'تایید مالی با کد پیامکی')->first()['changed']);

        // The manager's activity marks each of these changes.
        $feed = app(UserActivity::class)->feed($this->manager->fresh(), 50, 'lists')['entries'];
        $cell = collect($feed)->firstWhere('text', '«حقوق پایه» نفر کریمی');
        $this->assertSame('bg-orange-500', $cell['tone']);
        $this->assertStringContainsString('بعد از «تایید مالی · نهایی»', $cell['context']);
    }

    public function test_changes_to_open_lists_carry_no_mark(): void
    {
        $this->editor->saveCells($this->manager, $this->sheet, [['row' => $this->rowB->id, 'column' => $this->openColumn->id, 'value' => '4', 'version' => 0]]);

        $this->assertNull(ChangeLog::where('action', 'cell.update')->sole()->meta);
        $sp = $this->sheetProject($this->projectB);
        $timeline = app(ListHistory::class)->timelines($this->sheet, collect([$sp]))[$sp->id];
        $this->assertSame([], collect($timeline)->filter(fn ($e) => str_contains($e['text'], 'بعد از'))->all());
    }

    public function test_each_person_and_each_sitting_gets_its_own_timeline_line_on_its_own_list(): void
    {
        $this->sheetProject($this->projectA)->update(['stage' => Stage::CeoApproved]);
        $this->sheetProject($this->projectB)->update(['stage' => Stage::Final]);
        $otherManager = User::factory()->manager()->create();
        $edit = fn (User $user, $row, string $value) => $this->editor->saveCells($user, $this->sheet, [['row' => $row->id, 'column' => $this->openColumn->id, 'value' => $value]]);

        $edit($this->manager, $this->rowA, '1');
        $edit($this->manager, $this->rowB, '2'); // the other list: not on A's timeline
        $edit($this->manager, $this->rowA, '3');
        $edit($otherManager, $this->rowA, '4');
        $this->travel(45)->minutes();
        $edit($otherManager, $this->rowA, '5');

        $sheetProjects = collect([$this->sheetProject($this->projectA), $this->sheetProject($this->projectB)]);
        $timelines = app(ListHistory::class)->timelines($this->sheet, $sheetProjects);
        $linesA = collect($timelines[$sheetProjects[0]->id])->filter(fn ($e) => str_contains($e['text'], 'بعد از'))->values();
        $this->assertSame(['بعد از «تایید مدیرعامل» لیست را تغییر داد'], $linesA->pluck('text')->unique()->values()->all());
        $this->assertSame([$this->manager->nameWithTitle(), $otherManager->nameWithTitle(), $otherManager->nameWithTitle()], $linesA->pluck('by')->all());
        $this->assertStringContainsString('خالی ← ۱', $linesA[0]['detail']);
        $this->assertStringContainsString('۱ ← ۳', $linesA[0]['detail']);

        $linesB = collect($timelines[$sheetProjects[1]->id])->filter(fn ($e) => str_contains($e['text'], 'بعد از'))->values();
        $this->assertSame(['بعد از «تایید مالی · نهایی» لیست را تغییر داد'], $linesB->pluck('text')->all());
    }
}
