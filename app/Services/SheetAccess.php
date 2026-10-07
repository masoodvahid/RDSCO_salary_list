<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\Stage;
use App\Models\ListComment;
use App\Models\Note;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every permission rule of the sheet lives here, so the grid, exports and tests
 * all ask the same questions.
 *
 * Roles (each includes the one before it):
 *  - viewer:   see rows in scope, add notes
 *  - editor:   + edit unlocked cells of own projects while the project is in Draft and before the deadline
 *  - approver: + approve. Scoped to projects → project approval of each. All projects → CEO approval + record review
 *  - finance:  like an all-project approver, one step later: record review + the final approval, after the CEO
 *  - manager:  everything (structure, locked cells, rows, members, HR approval, record review, reopen)
 *
 * Approval chain: editor → project approver → manager (HR) → CEO (all-project approver) → finance (final).
 * From the CEO's approval on, the list is locked (Stage::isLocked()) for everyone but the manager (HR), who may
 * still change it at any stage: those changes are logged as made after approval (SheetEditor::afterApproval)
 * and the signatures show "changed after approval". Only a finance rejection sends a locked list back.
 */
final class SheetAccess
{
    public function canView(User $user): bool
    {
        return $user->is_active;
    }

    /** @return list<int>|null null means every project */
    public function projectScope(User $user): ?array
    {
        return $user->hasAllProjects() ? null : $user->projectIds();
    }

    /** The user is assigned to this project (managers and all-project users are not "members" of one). */
    public function isMemberOf(User $user, ?int $projectId): bool
    {
        return $projectId !== null && in_array($projectId, $user->projectIds(), true);
    }

    public function canViewRow(User $user, SheetRow $row): bool
    {
        $scope = $this->projectScope($user);

        return $scope === null || in_array((int) $row->project_id, $scope, true);
    }

    public function canViewProject(User $user, int $projectId): bool
    {
        $scope = $this->projectScope($user);

        return $scope === null || in_array($projectId, $scope, true);
    }

    /** Rows of a sheet the user may see, optionally narrowed to one project. */
    public function visibleRows(User $user, Sheet $sheet, ?int $projectId = null): Builder
    {
        $query = SheetRow::query()->where('sheet_id', $sheet->id);
        $scope = $this->projectScope($user);

        if ($scope !== null) {
            $query->whereIn('project_id', $scope);
        }
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query;
    }

    public function canManage(User $user): bool
    {
        return $user->is_active && $user->role === Role::Manager;
    }

    public function canEditCell(User $user, Sheet $sheet, SheetRow $row, SheetColumn $column, ?SheetProject $sheetProject, ?CarbonInterface $now = null): bool
    {
        return $this->canEditRowCells($user, $sheet, $row, $sheetProject, $now)
            && ($user->role === Role::Manager || ! $column->is_locked);
    }

    /**
     * Whether the user may edit the row's cells right now, before looking at column locks (managers may
     * also edit locked columns). canEditCell() = this + the lock; the grid checks it once per row.
     */
    public function canEditRowCells(User $user, Sheet $sheet, SheetRow $row, ?SheetProject $sheetProject, ?CarbonInterface $now = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $stage = $sheetProject?->stage ?? Stage::Draft;

        // HR may correct a list at any stage, even after the CEO's and finance's approval (logged as such).
        if ($user->role === Role::Manager) {
            return true;
        }

        if (! in_array($user->role, [Role::Editor, Role::Approver], true)) {
            return false;
        }

        return $this->isMemberOf($user, $row->project_id === null ? null : (int) $row->project_id)
            && $stage === Stage::Draft
            && ! $sheet->isPastDeadline($now);
    }

    /**
     * Editors and project approvers may fill their open columns from an Excel file while at least one
     * of their projects is still in Draft and the deadline has not passed (managers use the full import).
     */
    public function canImportValues(User $user, Sheet $sheet, ?CarbonInterface $now = null): bool
    {
        if (! $user->is_active || ! in_array($user->role, [Role::Editor, Role::Approver], true) || $sheet->isPastDeadline($now)) {
            return false;
        }
        $projectIds = $user->projectIds();

        return $projectIds !== [] && SheetProject::where('sheet_id', $sheet->id)
            ->whereIn('project_id', $projectIds)
            ->where('stage', Stage::Draft->value)
            ->exists();
    }

    /** Personnel fields, rows and their project: the manager, at any stage (see canEditRowCells). */
    public function canEditIdentity(User $user, ?SheetProject $sheetProject): bool
    {
        return $this->canManage($user);
    }

    /** Per-record approve/reject with a note. */
    public function canReview(User $user, ?SheetProject $sheetProject): bool
    {
        if (! $sheetProject || ! $user->is_active) {
            return false;
        }
        if ($user->role === Role::Manager) {
            return ! $sheetProject->stage->isLocked();
        }

        // Each of the last two approvers reviews the records at their own step.
        return ($user->isGlobalApprover() && $sheetProject->stage === Stage::HrApproved)
            || ($user->isFinance() && $sheetProject->stage === Stage::CeoApproved);
    }

    /** The stage this user's approval would move the project to, or null when they cannot approve now. */
    public function approvalTarget(User $user, SheetProject $sheetProject): ?Stage
    {
        if (! $user->is_active) {
            return null;
        }

        $stage = $sheetProject->stage;

        return match (true) {
            $user->role === Role::Approver
                && $this->isMemberOf($user, (int) $sheetProject->project_id)
                && $stage === Stage::Draft => Stage::ProjectApproved,
            $user->role === Role::Manager
                && in_array($stage, [Stage::Draft, Stage::ProjectApproved], true) => Stage::HrApproved,
            $user->isGlobalApprover() && $stage === Stage::HrApproved => Stage::CeoApproved,
            $user->isFinance() && $stage === Stage::CeoApproved => Stage::Final,
            default => null,
        };
    }

    /**
     * HR takes a list back for correction until the CEO has signed it. After that only a finance rejection
     * sends it back (lists the CEO signed before the finance step existed were final, and stay out of
     * HR's reach as they were).
     */
    public function canReopen(User $user, SheetProject $sheetProject): bool
    {
        return $this->canManage($user)
            && in_array($sheetProject->stage, [Stage::ProjectApproved, Stage::HrApproved], true);
    }

    /** Editors tell their approver the list is ready (no signature, no lock). */
    public function canSubmit(User $user, Sheet $sheet, SheetProject $sheetProject): bool
    {
        return $user->is_active
            && in_array($user->role, [Role::Editor, Role::Approver], true)
            && $this->isMemberOf($user, (int) $sheetProject->project_id)
            && $sheetProject->stage === Stage::Draft
            && ! $sheet->isPastDeadline();
    }

    public function canNote(User $user, SheetRow $row): bool
    {
        return $user->is_active && $this->canViewRow($user, $row);
    }

    /** Everyone who sees a project's list may comment on it, at any stage. */
    public function canComment(User $user, SheetProject $sheetProject): bool
    {
        return $user->is_active && $this->canViewProject($user, (int) $sheetProject->project_id);
    }

    /** The author (while they can still see the list) or a manager decides whether a comment is printed. */
    public function canSetCommentPrint(User $user, ListComment $comment, SheetProject $sheetProject): bool
    {
        return $this->canManage($user)
            || ((int) $comment->user_id === (int) $user->id && $this->canComment($user, $sheetProject));
    }

    /** As with row notes, only managers delete comments; the text stays in the change log. */
    public function canDeleteComment(User $user, ListComment $comment): bool
    {
        return $this->canManage($user);
    }

    /** Only managers remove notes (rejection reasons included); the removal stays in the change log. */
    public function canDeleteNote(User $user, Note $note): bool
    {
        return $this->canManage($user);
    }
}
