<?php

namespace App\Services;

use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Enums\Stage;
use App\Models\Note;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-record approve/reject with a note, and free notes on rows.
 *
 * A manager's rejection sends the project back to Draft (editors fix it);
 * a rejection by the CEO or finance sends it back to HR (ProjectApproved).
 */
final class ReviewService
{
    public function __construct(
        private readonly SheetAccess $access,
        private readonly ApprovalService $approvals,
        private readonly ChangeLogger $log,
    ) {}

    public function review(User $user, SheetRow $row, ReviewStatus $status, ?string $note = null): void
    {
        $sheetProject = $row->project_id
            ? SheetProject::where('sheet_id', $row->sheet_id)->where('project_id', $row->project_id)->first()
            : null;

        if (! $this->access->canReview($user, $sheetProject)) {
            throw new AuthorizationException('امکان بررسی این رکورد را ندارید.');
        }

        $note = trim((string) $note);
        if ($status === ReviewStatus::Rejected && $note === '') {
            throw ValidationException::withMessages(['rejectNote' => 'برای رد رکورد، یادداشت بنویسید تا پروژه بداند چه چیزی را اصلاح کند.']);
        }

        DB::transaction(function () use ($user, $row, $status, $note, $sheetProject) {
            $old = $row->review_status;
            $row->update([
                'review_status' => $status,
                'reviewed_by' => $status === ReviewStatus::Pending ? null : $user->id,
                'reviewed_at' => $status === ReviewStatus::Pending ? null : now(),
            ]);

            $this->log->record($row->sheet_id, $user, 'row.review', $row->id, null, $old->value, $status->value, $note !== '' ? ['note' => $note] : null);

            if ($status !== ReviewStatus::Rejected) {
                return;
            }

            Note::create([
                'sheet_id' => $row->sheet_id,
                'row_id' => $row->id,
                'user_id' => $user->id,
                'kind' => Note::KIND_REJECTION,
                'body' => mb_substr($note, 0, 2000),
            ]);

            $sheetProject->refresh();
            if ($user->role === Role::Manager) {
                $this->approvals->moveBack($user, $sheetProject, Stage::Draft, 'stage.return', 'رد رکورد توسط منابع انسانی');
            } elseif ($user->isGlobalApprover()) {
                $this->approvals->moveBack($user, $sheetProject, Stage::ProjectApproved, 'stage.return', 'رد رکورد توسط مدیرعامل');
            } elseif ($user->isFinance()) {
                $this->approvals->moveBack($user, $sheetProject, Stage::ProjectApproved, 'stage.return', 'رد رکورد توسط مالی');
            }
        });
    }

    public function addNote(User $user, SheetRow $row, string $body): Note
    {
        if (! $this->access->canNote($user, $row)) {
            throw new AuthorizationException('امکان یادداشت روی این ردیف را ندارید.');
        }

        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['newNote' => 'متن یادداشت خالی است.']);
        }

        $note = Note::create([
            'sheet_id' => $row->sheet_id,
            'row_id' => $row->id,
            'user_id' => $user->id,
            'kind' => Note::KIND_NOTE,
            'body' => mb_substr($body, 0, 2000),
        ]);
        $this->log->record($row->sheet_id, $user, 'note.create', $row->id);

        return $note;
    }

    public function deleteNote(User $user, Note $note): void
    {
        if (! $this->access->canDeleteNote($user, $note)) {
            throw new AuthorizationException('فقط مدیر می‌تواند یادداشت را حذف کند.');
        }

        DB::transaction(function () use ($user, $note) {
            // Keep the text and author in the log so a deleted rejection reason is still traceable.
            $this->log->record($note->sheet_id, $user, 'note.delete', $note->row_id, null, $note->body, null, [
                'kind' => $note->kind,
                'author_id' => $note->user_id,
                'created_at' => $note->created_at?->toDateTimeString(),
            ]);
            $note->delete();
        });
    }
}
