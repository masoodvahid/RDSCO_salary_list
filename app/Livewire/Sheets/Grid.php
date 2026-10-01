<?php

namespace App\Livewire\Sheets;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\Note;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PersonnelImporter;
use App\Services\ReviewService;
use App\Services\SheetAccess;
use App\Services\SheetEditor;
use App\Support\Digits;
use App\Support\Jalali;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Grid extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $sheetId;

    /** Project id, 'none' for rows without a project, or null for all (managers/all-project users). */
    #[Url(as: 'project')]
    public $projectFilter = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'review', except: '')]
    public string $reviewFilter = '';

    /** Which dialog is open: column|row|reject|notes|otp|projects|import|deadline|reopen */
    public ?string $modal = null;

    #[Locked]
    public ?int $targetId = null;

    public string $columnTitle = '';

    public string $columnType = 'number';

    public bool $columnLocked = false;

    /** Optional bounds for number columns; empty string = no limit. */
    public string $columnMin = '';

    public string $columnMax = '';

    /** @var array{first_name:string,last_name:string,personnel_code:string,national_code:string,project_id:int|string|null} */
    public array $newRow = ['first_name' => '', 'last_name' => '', 'personnel_code' => '', 'national_code' => '', 'project_id' => null];

    public string $rejectNote = '';

    public string $newNote = '';

    public string $otpCode = '';

    #[Locked]
    public ?int $otpChallengeId = null;

    /** @var list<int|string> */
    public array $monthProjectIds = [];

    public $importFile = null;

    public ?array $importResult = null;

    public string $deadlineInput = '';

    public string $reopenReason = '';

    public ?string $notice = null;

    public int $noticeId = 0;

    public function mount(Sheet $sheet): void
    {
        $this->sheetId = $sheet->id;
        $user = $this->user();
        abort_unless($this->access()->canView($user), 403);

        if (! $user->hasAllProjects()) {
            $this->projectFilter = $user->project_id;
        }
    }

    // ---------------------------------------------------------------- data

    #[Computed]
    public function sheet(): Sheet
    {
        return Sheet::findOrFail($this->sheetId);
    }

    #[Computed]
    public function columns(): Collection
    {
        return SheetColumn::where('sheet_id', $this->sheetId)->orderBy('position')->orderBy('id')->get();
    }

    /** All projects of the month, keyed by project id. */
    #[Computed]
    public function sheetProjects(): Collection
    {
        return SheetProject::where('sheet_id', $this->sheetId)
            ->with('project')
            ->get()
            ->sortBy(fn (SheetProject $sp) => $sp->project->name)
            ->keyBy('project_id');
    }

    /** Projects the user can filter by. */
    #[Computed]
    public function visibleProjects(): Collection
    {
        $scope = $this->access()->projectScope($this->user());

        return $scope === null
            ? $this->sheetProjects
            : $this->sheetProjects->filter(fn ($sp) => in_array((int) $sp->project_id, $scope, true));
    }

    public function currentProjectId(): ?int
    {
        $user = $this->user();
        if (! $user->hasAllProjects()) {
            return (int) $user->project_id;
        }

        $id = is_numeric($this->projectFilter) ? (int) $this->projectFilter : null;

        return $id && $this->sheetProjects->has($id) ? $id : null;
    }

    #[Computed]
    public function currentSheetProject(): ?SheetProject
    {
        $id = $this->currentProjectId();

        return $id ? $this->sheetProjects->get($id) : null;
    }

    #[Computed]
    public function rows(): Collection
    {
        $query = $this->access()->visibleRows($this->user(), $this->sheet, $this->currentProjectId())
            ->with('cells')
            ->withCount('notes')
            ->orderBy('position')
            ->orderBy('id');

        if ($this->projectFilter === 'none' && $this->user()->hasAllProjects()) {
            $query->whereNull('project_id');
        }

        $search = trim(Digits::toEnglish($this->search));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('national_code', 'like', $like)
                    ->orWhere('personnel_code', 'like', $like);
            });
        }

        if (ReviewStatus::tryFrom($this->reviewFilter)) {
            $query->where('review_status', $this->reviewFilter);
        }

        return $query->get();
    }

    private function refreshData(): void
    {
        unset($this->columns, $this->sheetProjects, $this->visibleProjects, $this->currentSheetProject, $this->rows, $this->sheet);
    }

    /** Changes when columns, rows, stages, notes or the deadline change (not on plain cell edits). */
    public function structureSignature(): string
    {
        $parts = [
            SheetColumn::where('sheet_id', $this->sheetId)->selectRaw('count(*) as c, max(updated_at) as u')->first()?->toArray(),
            SheetRow::where('sheet_id', $this->sheetId)->selectRaw('count(*) as c, max(updated_at) as u')->first()?->toArray(),
            SheetProject::where('sheet_id', $this->sheetId)->selectRaw('count(*) as c, max(updated_at) as u')->first()?->toArray(),
            Note::where('sheet_id', $this->sheetId)->count(),
            $this->sheet->deadline_at?->timestamp,
        ];

        return md5(json_encode($parts));
    }

    // ---------------------------------------------------------------- grid endpoints (JS)

    /** @param list<array<string, mixed>> $changes */
    #[Renderless]
    public function saveCells(array $changes): array
    {
        return app(SheetEditor::class)->saveCells($this->user(), $this->sheet, $changes);
    }

    #[Renderless]
    public function changesSince(?string $since = null, ?string $signature = null): array
    {
        $now = now();
        $from = $since ? Carbon::parse($since)->subSeconds(2) : $now->copy()->subMinute();

        $rowIds = $this->access()->visibleRows($this->user(), $this->sheet, $this->currentProjectId())->pluck('id');

        $cells = SheetCell::whereIn('row_id', $rowIds)
            ->where('updated_at', '>=', $from)
            ->get(['row_id', 'column_id', 'value', 'version'])
            ->map(fn (SheetCell $c) => [
                'row' => $c->row_id,
                'column' => $c->column_id,
                'field' => null,
                'value' => $c->value,
                'version' => $c->version,
            ])
            ->values()
            ->all();

        return ['now' => $now->toIso8601String(), 'cells' => $cells, 'signature' => $this->structureSignature()];
    }

    // ---------------------------------------------------------------- filters

    public function filterProject($projectId): void
    {
        if (! $this->user()->hasAllProjects()) {
            return;
        }
        $this->projectFilter = $projectId === 'none' ? 'none' : (is_numeric($projectId) ? (int) $projectId : null);
        $this->refreshData();
    }

    // ---------------------------------------------------------------- columns (manager)

    public function openColumn(?int $columnId = null): void
    {
        $this->authorizeManage();
        $this->closeModal();

        if ($columnId) {
            $column = $this->findColumn($columnId);
            $this->targetId = $column->id;
            $this->columnTitle = $column->title;
            $this->columnType = $column->type->value;
            $this->columnLocked = $column->is_locked;
            $this->columnMin = Digits::group($column->min_value);
            $this->columnMax = Digits::group($column->max_value);
        }
        $this->modal = 'column';
    }

    public function saveColumn(SheetEditor $editor): void
    {
        if ($this->targetId) {
            $outside = $editor->updateColumn($this->user(), $this->findColumn($this->targetId), $this->columnTitle, $this->columnType, $this->columnLocked, $this->columnMin, $this->columnMax);
            $this->flash($outside
                ? 'ستون به‌روز شد. '.Digits::toPersian($outside).' مقدار فعلی خارج از بازه است و با رنگ قرمز مشخص شده.'
                : 'ستون به‌روز شد.');
        } else {
            $editor->addColumn($this->user(), $this->sheet, $this->columnTitle, $this->columnType, $this->columnLocked, $this->columnMin, $this->columnMax);
            $this->flash('ستون اضافه شد.');
        }
        $this->closeModal();
        $this->refreshData();
    }

    public function deleteColumn(SheetEditor $editor): void
    {
        if (! $this->targetId) {
            return;
        }
        $editor->deleteColumn($this->user(), $this->findColumn($this->targetId));
        $this->closeModal();
        $this->refreshData();
        $this->flash('ستون حذف شد.');
    }

    public function moveColumn(SheetEditor $editor, int $direction): void
    {
        if (! $this->targetId) {
            return;
        }
        $editor->moveColumn($this->user(), $this->findColumn($this->targetId), $direction);
        $this->refreshData();
    }

    // ---------------------------------------------------------------- rows (manager)

    public function openRow(): void
    {
        $this->authorizeManage();
        $this->closeModal();
        $this->newRow = ['first_name' => '', 'last_name' => '', 'personnel_code' => '', 'national_code' => '', 'project_id' => $this->currentProjectId()];
        $this->modal = 'row';
    }

    public function saveRow(SheetEditor $editor): void
    {
        $editor->addRow($this->user(), $this->sheet, $this->newRow);
        $this->closeModal();
        $this->refreshData();
        $this->flash('ردیف اضافه شد.');
    }

    public function deleteRow(SheetEditor $editor, int $rowId): void
    {
        $editor->deleteRow($this->user(), $this->findRow($rowId));
        $this->refreshData();
        $this->flash('ردیف حذف شد.');
    }

    public function setRowProject(SheetEditor $editor, int $rowId, $projectId = null): void
    {
        $editor->setRowProject($this->user(), $this->findRow($rowId), is_numeric($projectId) ? (int) $projectId : null);
        $this->refreshData();
    }

    // ---------------------------------------------------------------- review & notes

    public function approveRow(ReviewService $reviews, int $rowId): void
    {
        $row = $this->findRow($rowId);
        $status = $row->review_status === ReviewStatus::Approved ? ReviewStatus::Pending : ReviewStatus::Approved;
        $reviews->review($this->user(), $row, $status);
        $this->refreshData();
    }

    public function openReject(int $rowId): void
    {
        $row = $this->findRow($rowId);
        $this->closeModal();
        $this->targetId = $row->id;
        $this->modal = 'reject';
    }

    public function confirmReject(ReviewService $reviews): void
    {
        $reviews->review($this->user(), $this->findRow((int) $this->targetId), ReviewStatus::Rejected, $this->rejectNote);
        $this->closeModal();
        $this->refreshData();
        $this->flash('رکورد رد شد و یادداشت برای پروژه ثبت شد.');
    }

    public function openNotes(int $rowId): void
    {
        $row = $this->findRow($rowId);
        $this->closeModal();
        $this->targetId = $row->id;
        $this->modal = 'notes';
    }

    public function addNote(ReviewService $reviews): void
    {
        $reviews->addNote($this->user(), $this->findRow((int) $this->targetId), $this->newNote);
        $this->newNote = '';
        $this->refreshData();
    }

    public function deleteNote(ReviewService $reviews, int $noteId): void
    {
        $row = $this->findRow((int) $this->targetId);
        $reviews->deleteNote($this->user(), Note::where('row_id', $row->id)->findOrFail($noteId));
        $this->refreshData();
        $this->flash('یادداشت حذف شد.');
    }

    // ---------------------------------------------------------------- workflow

    public function submitProject(ApprovalService $approvals, int $sheetProjectId): void
    {
        $approvals->submit($this->user(), $this->sheet, $this->findSheetProject($sheetProjectId));
        $this->refreshData();
        $this->flash('لیست برای تایید مدیر پروژه ارسال شد.');
    }

    public function startApproval(ApprovalService $approvals, int $sheetProjectId): void
    {
        $sheetProject = $this->findSheetProject($sheetProjectId);
        $this->resetErrorBag();
        $this->otpCode = '';
        $challenge = $approvals->start($this->user(), $sheetProject, request()->ip());

        $this->modal = 'otp';
        $this->targetId = $sheetProject->id;
        $this->otpChallengeId = $challenge->id;
    }

    public function resendApproval(ApprovalService $approvals): void
    {
        if ($this->targetId) {
            $this->startApproval($approvals, $this->targetId);
        }
    }

    public function confirmApproval(ApprovalService $approvals): void
    {
        $challenge = OtpChallenge::where('user_id', $this->user()->id)->findOrFail((int) $this->otpChallengeId);
        $approval = $approvals->confirm($this->user(), $challenge, $this->otpCode, request()->ip(), request()->userAgent());

        $this->closeModal();
        $this->refreshData();
        $this->flash($approval->stage->actionLabel().' ثبت شد.');
    }

    public function openReopen(int $sheetProjectId): void
    {
        $sheetProject = $this->findSheetProject($sheetProjectId);
        $this->closeModal();
        $this->targetId = $sheetProject->id;
        $this->modal = 'reopen';
    }

    public function confirmReopen(ApprovalService $approvals): void
    {
        $approvals->reopen($this->user(), $this->findSheetProject((int) $this->targetId), trim($this->reopenReason) ?: null);
        $this->closeModal();
        $this->refreshData();
        $this->flash('لیست بازگشایی شد و برای اصلاح به پروژه برگشت.');
    }

    // ---------------------------------------------------------------- month settings (manager)

    public function openProjects(): void
    {
        $this->authorizeManage();
        $this->closeModal();
        $this->monthProjectIds = $this->sheetProjects->keys()->map(fn ($id) => (string) $id)->values()->all();
        $this->modal = 'projects';
    }

    public function saveProjects(SheetEditor $editor): void
    {
        $editor->setMonthProjects($this->user(), $this->sheet, $this->monthProjectIds);
        $this->closeModal();
        $this->refreshData();
        $this->flash('پروژه‌های این ماه به‌روز شد.');
    }

    public function openDeadline(): void
    {
        $this->authorizeManage();
        $this->closeModal();
        $this->deadlineInput = Jalali::formatShort($this->sheet->deadline_at);
        $this->modal = 'deadline';
    }

    public function saveDeadline(SheetEditor $editor): void
    {
        $date = Jalali::parse($this->deadlineInput);
        if (! $date) {
            $this->addError('deadlineInput', 'تاریخ را به شکل ۱۴۰۵/۰۷/۱۴ وارد کنید.');

            return;
        }
        $editor->setDeadline($this->user(), $this->sheet, Jalali::toCarbon(...$date)->endOfDay());
        $this->closeModal();
        $this->refreshData();
        $this->flash('مهلت تکمیل تغییر کرد.');
    }

    public function openImport(): void
    {
        $this->authorizeManage();
        $this->closeModal();
        $this->modal = 'import';
    }

    public function import(PersonnelImporter $importer): void
    {
        $this->authorizeManage();
        $this->validate(
            ['importFile' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt']],
            [
                'importFile.required' => 'فایل را انتخاب کنید.',
                'importFile.max' => 'حداکثر حجم فایل ۵ مگابایت است.',
                'importFile.extensions' => 'فقط فایل XLSX یا CSV.',
            ],
        );

        $this->importResult = $importer->import(
            $this->user(),
            $this->sheet,
            $this->importFile->getRealPath(),
            $this->importFile->getClientOriginalExtension(),
        );
        $this->importFile = null;
        $this->refreshData();
    }

    public function closeModal(): void
    {
        $this->modal = null;
        $this->targetId = null;
        $this->otpChallengeId = null;
        $this->reset(['columnTitle', 'columnType', 'columnLocked', 'columnMin', 'columnMax', 'rejectNote', 'newNote', 'otpCode', 'importFile', 'importResult', 'deadlineInput', 'reopenReason']);
        $this->resetErrorBag();
    }

    // ---------------------------------------------------------------- helpers

    private function user(): User
    {
        return auth()->user();
    }

    private function access(): SheetAccess
    {
        return app(SheetAccess::class);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->access()->canManage($this->user()), 403);
    }

    private function flash(string $message): void
    {
        $this->notice = $message;
        $this->noticeId++;
    }

    private function findRow(int $rowId): SheetRow
    {
        $row = SheetRow::where('sheet_id', $this->sheetId)->findOrFail($rowId);
        abort_unless($this->access()->canViewRow($this->user(), $row), 403);

        return $row;
    }

    private function findColumn(int $columnId): SheetColumn
    {
        return SheetColumn::where('sheet_id', $this->sheetId)->findOrFail($columnId);
    }

    private function findSheetProject(int $sheetProjectId): SheetProject
    {
        $sheetProject = SheetProject::where('sheet_id', $this->sheetId)->findOrFail($sheetProjectId);
        abort_unless($this->access()->canViewProject($this->user(), (int) $sheetProject->project_id), 403);

        return $sheetProject;
    }

    /** @return array<int, string> */
    private function totals(Collection $rows, Collection $columns): array
    {
        $totals = [];
        foreach ($columns as $column) {
            if (! $column->isNumber()) {
                continue;
            }
            $sum = '0';
            foreach ($rows as $row) {
                $value = $row->cells->firstWhere('column_id', $column->id)?->value;
                if ($value !== null && Digits::normalizeNumber($value) !== false) {
                    $sum = Digits::add($sum, $value);
                }
            }
            $totals[$column->id] = $sum;
        }

        return $totals;
    }

    public function render()
    {
        $user = $this->user();
        $access = $this->access();
        $sheet = $this->sheet;
        $current = $this->currentSheetProject;
        $rows = $this->rows;
        $columns = $this->columns;

        $approvals = collect();
        $currentHash = null;
        if ($current) {
            $approvals = $current->approvals()->with('user')->get();
            if ($approvals->whereNull('revoked_at')->isNotEmpty()) {
                $currentHash = app(ApprovalService::class)->dataHash($current);
            }
        }

        $modalData = [];
        if ($this->modal === 'notes' && $this->targetId) {
            $modalData['notesRow'] = SheetRow::where('sheet_id', $this->sheetId)->with('notes.user')->find($this->targetId);
        }
        if (in_array($this->modal, ['reject'], true) && $this->targetId) {
            $modalData['targetRow'] = SheetRow::where('sheet_id', $this->sheetId)->find($this->targetId);
        }
        if (in_array($this->modal, ['otp', 'reopen'], true) && $this->targetId) {
            $modalData['targetProject'] = $this->sheetProjects->firstWhere('id', $this->targetId);
            $modalData['otpTarget'] = $modalData['targetProject'] ? $access->approvalTarget($user, $modalData['targetProject']) : null;
        }
        if ($this->modal === 'projects') {
            $modalData['allProjects'] = Project::where('is_active', true)
                ->orWhereIn('id', $this->sheetProjects->keys())
                ->orderBy('name')
                ->get();
        }

        return view('livewire.sheets.grid', [
            'user' => $user,
            'access' => $access,
            'sheet' => $sheet,
            'rows' => $rows,
            'columns' => $columns,
            'sheetProjects' => $this->sheetProjects,
            'isManager' => $access->canManage($user),
            'currentSp' => $current,
            'approvalTarget' => $current ? $access->approvalTarget($user, $current) : null,
            'canReopen' => $current !== null && $access->canReopen($user, $current),
            'canSubmit' => $current !== null && $access->canSubmit($user, $sheet, $current),
            'approvals' => $approvals,
            'currentHash' => $currentHash,
            'totals' => $this->totals($rows, $columns),
            'unassignedCount' => $access->canManage($user) ? SheetRow::where('sheet_id', $this->sheetId)->whereNull('project_id')->count() : 0,
            'gridConfig' => [
                'poll' => 12,
                'syncedAt' => now()->toIso8601String(),
                'signature' => $this->structureSignature(),
            ],
            'draft' => Stage::Draft,
            ...$modalData,
        ])->title('شیت '.$sheet->title());
    }
}
