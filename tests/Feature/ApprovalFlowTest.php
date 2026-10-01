<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\Approval;
use App\Models\Note;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ReviewService;
use App\Services\SheetEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_full_chain_project_hr_finance(): void
    {
        $approver = User::factory()->approver($this->projectA)->create();
        $finance = User::factory()->approver()->create();

        $first = $this->approve($approver, Stage::ProjectApproved);
        $this->assertSame(64, strlen($first->data_hash));
        $this->assertSame('tukahr-approval', $this->sms->sent[0]['template']);

        $this->approve($this->manager, Stage::HrApproved);
        $this->approve($finance, Stage::Final);

        $this->assertSame(3, Approval::whereNull('revoked_at')->count());
        $this->assertFalse(app(\App\Services\SheetAccess::class)->canEditCell(
            $this->manager, $this->sheet, $this->rowA, $this->openColumn, $this->sheetProject($this->projectA)
        ));
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

    public function test_finance_rejection_returns_the_list_to_hr(): void
    {
        $finance = User::factory()->approver()->create();
        $this->approve($this->manager, Stage::HrApproved);

        app(ReviewService::class)->review($finance, $this->rowA, ReviewStatus::Rejected, 'مساعده بیش از سقف است.');

        $this->assertSame(Stage::ProjectApproved, $this->sheetProject($this->projectA)->stage);
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
