<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Livewire\Sheets\Grid;
use App\Models\ChangeLog;
use App\Models\ListComment;
use App\Models\SheetCell;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ListHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/** Comments on a project's whole list and its approval timeline, under the grid and in the print. */
class ListCommentsTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private User $editor;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->editor = User::factory()->editor($this->projectA)->create(['name' => 'سمیرا امینی', 'job_title' => 'مسئول اداری']);
        $this->approver = User::factory()->approver($this->projectA)->create(['name' => 'کاوه مرادی']);
    }

    private function approve(User $user): void
    {
        $approvals = app(ApprovalService::class);
        $challenge = $approvals->start($user, $this->sheetProject($this->projectA));
        $approvals->confirm($user, $challenge, $this->sms->lastCodeFor($user->mobile));
    }

    public function test_members_comment_on_their_projects_list_with_print_off_by_default(): void
    {
        Livewire::actingAs($this->editor)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->assertSee('کامنت‌های لیست')
            ->set('commentBody', "اضافه‌کار این ماه با گزارش تردد تطبیق داده شد.\nلطفاً بررسی کنید.")
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSet('commentBody', '')
            ->assertSee('اضافه‌کار این ماه با گزارش تردد تطبیق داده شد.')
            ->assertSee('سمیرا امینی (مسئول اداری)')
            ->call('addComment')
            ->assertHasErrors('commentBody');

        $comment = ListComment::sole();
        $this->assertFalse($comment->in_print);
        $this->assertSame($this->sheetProject($this->projectA)->id, $comment->sheet_project_id);
        $this->assertSame(1, ChangeLog::where('action', 'comment.create')->count());

        // A member of another project cannot comment on this list.
        $outsider = User::factory()->viewer($this->projectB)->create();
        $this->expectException(AuthorizationException::class);
        app(ListHistory::class)->addComment($outsider, $this->sheetProject($this->projectA), 'سلام');
    }

    public function test_the_author_or_a_manager_decides_printing_and_only_managers_delete(): void
    {
        $history = app(ListHistory::class);
        $comment = $history->addComment($this->editor, $this->sheetProject($this->projectA), 'برای چاپ', inPrint: false);

        $history->setCommentPrint($this->editor, $comment, true);
        $this->assertTrue($comment->fresh()->in_print);
        $history->setCommentPrint($this->manager, $comment->fresh(), false);
        $this->assertFalse($comment->fresh()->in_print);

        try {
            $history->setCommentPrint($this->approver, $comment->fresh(), true);
            $this->fail('Only the author or a manager.');
        } catch (AuthorizationException) {
        }
        try {
            $history->deleteComment($this->editor, $comment->fresh());
            $this->fail('Only managers delete.');
        } catch (AuthorizationException) {
        }

        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('filterProject', $this->projectA->id)
            ->call('deleteComment', $comment->id);

        $this->assertNull($comment->fresh());
        $this->assertSame('برای چاپ', ChangeLog::where('action', 'comment.delete')->value('old_value'));
    }

    public function test_the_manager_sees_the_approval_timeline_with_date_and_time(): void
    {
        app(ApprovalService::class)->submit($this->editor, $this->sheet, $this->sheetProject($this->projectA));
        $this->approve($this->approver);
        $this->approve($this->manager);
        SheetCell::create(['row_id' => $this->rowA->id, 'column_id' => $this->openColumn->id, 'value' => '5', 'version' => 1]);

        $timeline = app(ListHistory::class)->timelines($this->sheet, collect([$this->sheetProject($this->projectA)]), [
            $this->sheetProject($this->projectA)->id => app(ApprovalService::class)->dataHash($this->sheetProject($this->projectA)),
        ])[$this->sheetProject($this->projectA)->id];

        $this->assertSame(['لیست حقوق ماه را ساخت', 'لیست را برای تایید فرستاد', 'تایید پروژه با کد پیامکی', 'تایید منابع انسانی با کد پیامکی'], array_column($timeline, 'text'));
        $this->assertSame('سمیرا امینی (مسئول اداری)', $timeline[1]['by']);
        $this->assertSame('کاوه مرادی', $timeline[2]['by']);
        // The data changed after both signatures.
        $this->assertTrue($timeline[3]['changed']);

        Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('filterProject', $this->projectA->id)
            ->assertSee('روند تایید لیست دماوند')
            ->assertSee('لیست را برای تایید فرستاد')
            ->assertSee('تایید منابع انسانی با کد پیامکی')
            ->assertSee('ساعت');

        // Reopening shows up and voids the signatures.
        app(ApprovalService::class)->reopen($this->manager, $this->sheetProject($this->projectA), 'اصلاح اضافه‌کار');
        $timeline = app(ListHistory::class)->timelines($this->sheet, collect([$this->sheetProject($this->projectA)]))[$this->sheetProject($this->projectA)->id];
        $last = end($timeline);
        $this->assertSame('لیست را برای اصلاح بازگشایی کرد', $last['text']);
        $this->assertSame('اصلاح اضافه‌کار', $last['detail']);
        $this->assertTrue($timeline[2]['revoked']);
    }

    public function test_print_shows_the_timeline_and_ticked_comments_unless_turned_off(): void
    {
        $history = app(ListHistory::class);
        $history->addComment($this->editor, $this->sheetProject($this->projectA), 'کامنت چاپی', inPrint: true);
        $history->addComment($this->editor, $this->sheetProject($this->projectA), 'کامنت داخلی');
        app(ApprovalService::class)->submit($this->editor, $this->sheet, $this->sheetProject($this->projectA));

        $this->actingAs($this->manager);
        $url = fn (array $q = []) => route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectA->id] + $q);

        $this->get($url())->assertOk()
            ->assertSee('کامنت چاپی')
            ->assertDontSee('کامنت داخلی')
            ->assertSee('لیست را برای تایید فرستاد');

        $this->get($url(['timeline' => '0']))->assertSee('کامنت چاپی')->assertDontSee('لیست را برای تایید فرستاد');
        $this->get($url(['comments' => '0']))->assertDontSee('کامنت چاپی')->assertSee('لیست را برای تایید فرستاد');
        $this->get($url(['comments' => '0', 'timeline' => '0']))->assertDontSee('کامنت چاپی')->assertDontSee('لیست را برای تایید فرستاد');
    }

    public function test_a_project_with_comments_stays_in_the_month(): void
    {
        app(ListHistory::class)->addComment($this->manager, $this->sheetProject($this->projectB), 'یادداشت ماه');
        $this->rowB->delete();

        $this->expectException(ValidationException::class);
        app(\App\Services\SheetEditor::class)->setMonthProjects($this->manager, $this->sheet, [$this->projectA->id]);
    }
}
