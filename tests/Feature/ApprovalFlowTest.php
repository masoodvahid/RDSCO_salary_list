<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\Approval;
use App\Models\ChangeLog;
use App\Models\Note;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ChangeLogger;
use App\Services\ListHistory;
use App\Services\ReviewService;
use App\Services\SheetAccess;
use App\Services\SheetEditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private ApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->approvals = app(ApprovalService::class);
    }

    private function approve(User $user, Stage $expected): Approval
    {
        $sp = $this->sheetProject($this->projectA);
        $challenge = $this->approvals->start($user, $sp);
        $approval = $this->approvals->confirm($user, $challenge, $this->sms->lastCodeFor($user->mobile));

        $this->assertSame($expected, $this->sheetProject($this->projectA)->stage);

        return $approval;
    }

    private function lastReturnReason(): ?string
    {
        return ChangeLog::where('action', 'stage.return')->latest('id')->first()?->meta['reason'] ?? null;
    }

    public function test_full_chain_project_hr_ceo_finance(): void
    {
        $approver = User::factory()->approver($this->projectA)->create();
        $ceo = User::factory()->approver()->create();
        $finance = User::factory()->finance()->create();

        $first = $this->approve($approver, Stage::ProjectApproved);
        $this->assertSame(64, strlen($first->data_hash));
        $this->assertSame('tukahr-approval', $this->sms->sent[0]['template']);

        $this->approve($this->manager, Stage::HrApproved);
        $this->approve($ceo, Stage::CeoApproved);
        // Locked for the project, but HR may still correct it (logged as after approval).
        $this->assertFalse(app(SheetAccess::class)->canEditCell(
            $approver, $this->sheet, $this->rowA, $this->openColumn, $this->sheetProject($this->projectA)
        ));
        $this->assertTrue(app(SheetAccess::class)->canEditCell(
            $this->manager, $this->sheet, $this->rowA, $this->openColumn, $this->sheetProject($this->projectA)
        ));
        $this->approve($finance, Stage::Final);

        $this->assertSame([1, 2, 3, 4], Approval::whereNull('revoked_at')->orderBy('id')->get()->map(fn (Approval $a) => $a->stage->value)->all());
        $this->assertSame([$approver->id, $this->manager->id, $ceo->id, $finance->id], Approval::orderBy('id')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue(app(SheetAccess::class)->canEditCell(
            $this->manager, $this->sheet, $this->rowA, $this->openColumn, $this->sheetProject($this->projectA)
        ));
    }

    public function test_lists_signed_before_the_ceo_step_read_as_ceo_approved_and_wait_for_finance(): void
    {
        // What the previous version stored when the all-project approver gave the then-final approval
        // (labelled "finance", given by the CEO): stage 3 on the list, a signature at 3 and a 2 → 3 log.
        $ceo = User::factory()->approver()->create();
        $sp = $this->sheetProject($this->projectA);
        $hash = $this->approvals->dataHash($sp);
        DB::table('sheet_projects')->where('id', $sp->id)->update(['stage' => 3]);
        $approvalId = DB::table('approvals')->insertGetId([
            'sheet_project_id' => $sp->id, 'stage' => 3, 'user_id' => $ceo->id, 'data_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ChangeLogger::class)->record($this->sheet->id, $ceo, 'stage.approve', null, null, '2', '3', ['project_id' => $this->projectA->id, 'approval_id' => $approvalId, 'skipped' => []]);

        $sp = $this->sheetProject($this->projectA);
        $this->assertSame(Stage::CeoApproved, $sp->stage);
        $this->assertSame('تایید مدیرعامل', $sp->stage->label());
        $this->assertSame('تایید مدیرعامل', Approval::findOrFail($approvalId)->stage->actionLabel());
        $event = collect(app(ListHistory::class)->timelines($this->sheet, collect([$sp]), [$sp->id => $hash])[$sp->id])->last();
        $this->assertSame('تایید مدیرعامل با کد پیامکی', $event['text']);
        $this->assertFalse($event['revoked']);
        $this->assertFalse($event['changed']);

        // Locked for the project; HR cannot take it back (no reopen), though HR may still correct it.
        $access = app(SheetAccess::class);
        $this->assertFalse($access->canEditCell(User::factory()->approver($this->projectA)->create(), $this->sheet, $this->rowA, $this->openColumn, $sp));
        $this->assertFalse($access->canReopen($this->manager, $sp));
        $this->assertNull($access->approvalTarget($ceo, $sp));

        // The new finance manager gives the final approval; the CEO's signature stays valid next to it.
        $this->approve(User::factory()->finance()->create(), Stage::Final);
        $this->assertSame(4, (int) DB::table('sheet_projects')->where('id', $sp->id)->value('stage'));
        $this->assertSame(2, Approval::whereNull('revoked_at')->count());
        $this->assertNull(Approval::findOrFail($approvalId)->revoked_at);
    }

    public function test_manager_can_approve_before_the_project_and_the_skip_is_recorded(): void
    {
        $approval = $this->approve($this->manager, Stage::HrApproved);

        $this->assertSame([1], $approval->skipped_stages);
    }

    public function test_changing_data_after_the_code_was_sent_blocks_the_approval(): void
    {
        $approver = User::factory()->approver($this->projectA)->create();
        $challenge = $this->approvals->start($approver, $this->sheetProject($this->projectA));

        app(SheetEditor::class)->saveCells($this->manager, $this->sheet, [
            ['row' => $this->rowA->id, 'column' => $this->openColumn->id, 'value' => '5'],
        ]);

        try {
            $this->approvals->confirm($approver, $challenge, $this->sms->lastCodeFor($approver->mobile));
            $this->fail('Approval should have been refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('otpCode', $e->errors());
        }

        $this->assertSame(Stage::Draft, $this->sheetProject($this->projectA)->stage);
        $this->assertSame(0, Approval::count());
    }

    public function test_wrong_codes_are_refused_and_then_locked_out(): void
    {
        $approver = User::factory()->approver($this->projectA)->create();
        $challenge = $this->approvals->start($approver, $this->sheetProject($this->projectA));
        $right = $this->sms->lastCodeFor($approver->mobile);
        $wrong = $right === '000000' ? '111111' : '000000';

        for ($i = 0; $i < config('tuka.otp.max_attempts'); $i++) {
            try {
                $this->approvals->confirm($approver, $challenge->fresh(), $wrong);
            } catch (ValidationException) {
            }
        }

        $this->expectException(ValidationException::class);
        $this->approvals->confirm($approver, $challenge->fresh(), $right);
    }

    public function test_manager_rejecting_a_record_returns_the_list_to_the_project(): void
    {
        $approver = User::factory()->approver($this->projectA)->create();
        $this->approve($approver, Stage::ProjectApproved);

        app(ReviewService::class)->review($this->manager, $this->rowA, ReviewStatus::Rejected, 'اضافه‌کار اشتباه است.');

        $this->assertSame(Stage::Draft, $this->sheetProject($this->projectA)->stage);
        $this->assertSame(ReviewStatus::Rejected, $this->rowA->fresh()->review_status);
        $this->assertNotNull(Approval::first()->revoked_at);
        $this->assertSame(Note::KIND_REJECTION, Note::first()->kind);
    }

    public function test_ceo_rejection_returns_the_list_to_hr(): void
    {
        $ceo = User::factory()->approver()->create();
        $this->approve($this->manager, Stage::HrApproved);

        app(ReviewService::class)->review($ceo, $this->rowA, ReviewStatus::Rejected, 'مساعده بیش از سقف است.');

        $this->assertSame(Stage::ProjectApproved, $this->sheetProject($this->projectA)->stage);
        $this->assertNotNull(Approval::first()->revoked_at);
        $this->assertSame('رد رکورد توسط مدیرعامل', $this->lastReturnReason());
    }

    public function test_finance_rejection_returns_the_list_to_hr_and_every_step_signs_again(): void
    {
        $ceo = User::factory()->approver()->create();
        $finance = User::factory()->finance()->create();
        $this->approve($this->manager, Stage::HrApproved);
        $this->approve($ceo, Stage::CeoApproved);

        app(ReviewService::class)->review($finance, $this->rowA, ReviewStatus::Rejected, 'مبلغ مساعده با سند نمی‌خواند.');

        $this->assertSame(Stage::ProjectApproved, $this->sheetProject($this->projectA)->stage);
        $this->assertSame(0, Approval::whereNull('revoked_at')->count());
        $this->assertSame('رد رکورد توسط مالی', $this->lastReturnReason());

        $this->approve($this->manager, Stage::HrApproved);
        $this->approve($ceo, Stage::CeoApproved);
        $this->approve($finance, Stage::Final);
        $this->assertSame(3, Approval::whereNull('revoked_at')->count());
    }

    public function test_after_the_ceo_signs_neither_hr_nor_the_ceo_can_take_the_list_back(): void
    {
        $ceo = User::factory()->approver()->create();
        $this->approve($this->manager, Stage::HrApproved);
        $this->approve($ceo, Stage::CeoApproved);

        $attempts = [
            'HR reopens' => fn () => $this->approvals->reopen($this->manager, $this->sheetProject($this->projectA), 'اصلاح'),
            'HR rejects a record' => fn () => app(ReviewService::class)->review($this->manager, $this->rowA->fresh(), ReviewStatus::Rejected, 'اشتباه است.'),
            'the CEO rejects a record' => fn () => app(ReviewService::class)->review($ceo, $this->rowA->fresh(), ReviewStatus::Rejected, 'اشتباه است.'),
        ];
        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} should be refused.");
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(Stage::CeoApproved, $this->sheetProject($this->projectA)->stage);
        $this->assertSame(2, Approval::whereNull('revoked_at')->count());
    }

    public function test_rejection_requires_a_note(): void
    {
        $this->expectException(ValidationException::class);
        app(ReviewService::class)->review($this->manager, $this->rowA, ReviewStatus::Rejected, '  ');
    }

    public function test_reopen_revokes_approvals(): void
    {
        $this->approve($this->manager, Stage::HrApproved);
        $this->approvals->reopen($this->manager, $this->sheetProject($this->projectA), 'اصلاح');

        $this->assertSame(Stage::Draft, $this->sheetProject($this->projectA)->stage);
        $this->assertSame(0, Approval::whereNull('revoked_at')->count());
        $this->assertSame(1, OtpChallenge::whereNotNull('consumed_at')->count());
    }
}
