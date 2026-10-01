<?php

namespace App\Services;

use App\Enums\Stage;
use App\Models\Approval;
use App\Models\OtpChallenge;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage approvals signed with an SMS code. Each approval stores a SHA-256 of the exact
 * data approved, and the code is bound to that hash: if anything changes between sending
 * the code and entering it, the approval is refused.
 */
final class ApprovalService
{
    public function __construct(
        private readonly SheetAccess $access,
        private readonly OtpService $otp,
        private readonly ChangeLogger $log,
    ) {}

    /** Canonical hash of one project's part of a sheet: columns + personnel + values. */
    public function dataHash(SheetProject $sheetProject): string
    {
        $columns = SheetColumn::where('sheet_id', $sheetProject->sheet_id)
            ->orderBy('id')
            ->get(['id', 'title', 'type'])
            ->map(fn (SheetColumn $c) => [$c->id, $c->title, $c->type->value])
            ->all();

        $rows = SheetRow::where('sheet_id', $sheetProject->sheet_id)
            ->where('project_id', $sheetProject->project_id)
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'personnel_code', 'national_code']);

        $cells = SheetCell::whereIn('row_id', $rows->pluck('id'))
            ->whereNotNull('value')
            ->orderBy('row_id')
            ->orderBy('column_id')
            ->get(['row_id', 'column_id', 'value'])
            ->groupBy('row_id');

        $payload = [
            'columns' => $columns,
            'rows' => $rows->map(fn (SheetRow $r) => [
                $r->id, $r->first_name, $r->last_name, $r->personnel_code, $r->national_code,
                ($cells->get($r->id) ?? collect())->map(fn ($c) => [(int) $c->column_id, (string) $c->value])->values()->all(),
            ])->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** Sends the approval code. Returns the challenge the code must be entered against. */
    public function start(User $user, SheetProject $sheetProject, ?string $ip = null): OtpChallenge
    {
        $target = $this->access->approvalTarget($user, $sheetProject);
        if ($target === null) {
            throw new AuthorizationException('در این مرحله امکان تایید این لیست را ندارید.');
        }

        if (! SheetRow::where('sheet_id', $sheetProject->sheet_id)->where('project_id', $sheetProject->project_id)->exists()) {
            throw ValidationException::withMessages(['approval' => 'این پروژه در این ماه ردیفی ندارد.']);
        }

        return $this->otp->issue($user, OtpChallenge::PURPOSE_APPROVAL, [
            'sheet_project_id' => $sheetProject->id,
            'stage' => $target->value,
            'hash' => $this->dataHash($sheetProject),
        ], $ip, 'otpCode');
    }

    public function confirm(User $user, OtpChallenge $challenge, string $code, ?string $ip = null, ?string $userAgent = null): Approval
    {
        if ($challenge->user_id !== $user->id || $challenge->purpose !== OtpChallenge::PURPOSE_APPROVAL) {
            throw new AuthorizationException;
        }

        $this->otp->verify($challenge, $code, 'otpCode');

        return DB::transaction(function () use ($user, $challenge, $ip, $userAgent) {
            $sheetProject = SheetProject::whereKey($challenge->context['sheet_project_id'] ?? 0)->lockForUpdate()->firstOrFail();
            $target = $this->access->approvalTarget($user, $sheetProject);

            if ($target === null || $target->value !== (int) ($challenge->context['stage'] ?? -1)) {
                throw ValidationException::withMessages(['otpCode' => 'وضعیت این لیست تغییر کرده است. صفحه را تازه کنید و دوباره تلاش کنید.']);
            }

            $hash = $this->dataHash($sheetProject);
            if (! hash_equals((string) ($challenge->context['hash'] ?? ''), $hash)) {
                throw ValidationException::withMessages(['otpCode' => 'داده‌ها بعد از ارسال کد تغییر کرده‌اند. دوباره کد بگیرید تا نسخه فعلی را تایید کنید.']);
            }

            $from = $sheetProject->stage;
            // range() counts down when start > end, so only build it when a stage was actually skipped.
            $skipped = $target->value - $from->value > 1 ? range($from->value + 1, $target->value - 1) : [];

            $approval = Approval::create([
                'sheet_project_id' => $sheetProject->id,
                'stage' => $target,
                'user_id' => $user->id,
                'data_hash' => $hash,
                'otp_challenge_id' => $challenge->id,
                'skipped_stages' => $skipped === [] ? null : $skipped,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            ]);

            $sheetProject->update(['stage' => $target]);

            $this->log->record($sheetProject->sheet_id, $user, 'stage.approve', null, null, (string) $from->value, (string) $target->value, [
                'project_id' => $sheetProject->project_id,
                'approval_id' => $approval->id,
                'skipped' => $skipped,
            ]);

            return $approval;
        });
    }

    /** Manager returns an approved list to the project (Draft) so it can be corrected. */
    public function reopen(User $user, SheetProject $sheetProject, ?string $reason = null): void
    {
        if (! $this->access->canReopen($user, $sheetProject)) {
            throw new AuthorizationException('امکان بازگشایی این لیست نیست.');
        }

        $this->moveBack($user, $sheetProject, Stage::Draft, 'stage.reopen', $reason);
    }

    /** Moves a list back to $to and revokes approvals above it. Used by reopen and record rejections. */
    public function moveBack(User $user, SheetProject $sheetProject, Stage $to, string $action, ?string $reason = null): void
    {
        if ($sheetProject->stage->value <= $to->value) {
            return;
        }

        DB::transaction(function () use ($user, $sheetProject, $to, $action, $reason) {
            $from = $sheetProject->stage;
            $sheetProject->update(['stage' => $to, 'submitted_at' => $to === Stage::Draft ? null : $sheetProject->submitted_at]);
            Approval::where('sheet_project_id', $sheetProject->id)
                ->where('stage', '>', $to->value)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->log->record($sheetProject->sheet_id, $user, $action, null, null, (string) $from->value, (string) $to->value, [
                'project_id' => $sheetProject->project_id,
                'reason' => $reason,
            ]);
        });
    }

    public function submit(User $user, Sheet $sheet, SheetProject $sheetProject): void
    {
        if (! $this->access->canSubmit($user, $sheet, $sheetProject)) {
            throw new AuthorizationException('امکان ارسال این لیست نیست.');
        }

        $sheetProject->update(['submitted_at' => now(), 'submitted_by' => $user->id]);
        $this->log->record($sheet->id, $user, 'stage.submit', meta: ['project_id' => $sheetProject->project_id]);
    }

    /** True when data changed after the given approval was signed. */
    public function changedSince(Approval $approval, ?string $currentHash): bool
    {
        return $currentHash !== null && ! hash_equals($approval->data_hash, $currentHash);
    }
}
