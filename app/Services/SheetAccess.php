<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\Stage;
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
 *  - approver: + approve. Scoped to projects → project approval of each. All projects → finance (final) approval + record review
 *  - manager:  everything (structure, locked cells, rows, members, HR approval, record review, reopen)
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
        if (! $user->is_active) {
            return false;
        }

        $stage = $sheetProject?->stage ?? Stage::Draft;

        if ($user->role === Role::Manager) {
            return $stage !== Stage::Final;
        }

        if (! in_array($user->role, [Role::Editor, Role::Approver], true)) {
            return false;
        }

        return $this->isMemberOf($user, $row->project_id === null ? null : (int) $row->project_id)
            && ! $column->is_locked
            && $stage === Stage::Draft
            && ! $sheet->isPastDeadline($now);
    }

    public function canEditIdentity(User $user, ?SheetProject $sheetProject): bool
    {
        return $this->canManage($user) && ($sheetProject?->stage ?? Stage::Draft) !== Stage::Final;
    }

    /** Per-record approve/reject with a note. */
    public function canReview(User $user, ?SheetProject $sheetProject): bool
    {
        if (! $sheetProject || ! $user->is_active) {
            return false;
        }
        if ($user->role === Role::Manager) {
            return $sheetProject->stage !== Stage::Final;
        }

        return $user->isGlobalApprover() && $sheetProject->stage === Stage::HrApproved;
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
            $user->isGlobalApprover() && $stage === Stage::HrApproved => Stage::Final,
            default => null,
        };
    }

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

    /** Only managers remove notes (rejection reasons included); the removal stays in the change log. */
    public function canDeleteNote(User $user, Note $note): bool
    {
        return $this->canManage($user);
    }
}
