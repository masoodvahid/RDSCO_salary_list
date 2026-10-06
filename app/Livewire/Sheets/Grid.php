<?php

namespace App\Livewire\Sheets;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\ChangeLog;
use App\Models\ListComment;
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
use App\Services\ListHistory;
use App\Services\PersonnelImporter;
use App\Services\ReviewService;
use App\Services\SheetAccess;
use App\Services\SheetEditor;
use App\Support\Digits;
use App\Support\Jalali;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

    /** Project id, 'none' for rows without a project (all-project users), or null for every project the user sees. */
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

    /** New comment on the whole list of the selected project. */
    public string $commentBody = '';

    public bool $commentInPrint = false;

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

    /**
     * Set by actions that leave the table as it is (dialogs, comments…): the "table" island is then not
     * re-rendered, which keeps those actions fast with many people. Every other render refreshes it, and
     * refreshData() always clears it.
     */
    protected bool $keepTable = false;

    public function mount(Sheet $sheet): void
    {
        $this->sheetId = $sheet->id;
        $user = $this->user();
        abort_unless($this->access()->canView($user), 403);

        // A member of a single project always works in it; members of several start on "all my projects".
        $this->projectFilter = $this->currentProjectId() ?? ($this->projectFilter === 'none' && $user->hasAllProjects() ? 'none' : null);
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
        $id = is_numeric($this->projectFilter) ? (int) $this->projectFilter : null;
        if ($id && $this->visibleProjects->has($id)) {
            return $id;
        }
        if (! $this->user()->hasAllProjects() && $this->visibleProjects->count() === 1) {
            return (int) $this->visibleProjects->keys()->first();
        }

        return null;
    }

    #[Computed]
    public function currentSheetProject(): ?SheetProject
    {
        $id = $this->currentProjectId();

        return $id ? $this->sheetProjects->get($id) : null;
    }

    /** The people the grid shows with the current filters, in grid order. */
    private function rowsQuery(): Builder
    {
        $query = $this->access()->visibleRows($this->user(), $this->sheet, $this->currentProjectId())
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

        return $query;
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->rowsQuery()->withCount('notes')->get();
    }

    /** Values of the shown rows (see cellValuesFor). */
    #[Computed]
    public function cellValues(): array
    {
        return $this->cellValuesFor($this->rows->pluck('id')->all());
    }

    /**
     * row id => column id => [value, version], read as plain arrays: hydrating thousands of cell models
     * was a large part of rendering a big list.
     *
     * @param  list<int>  $rowIds
     * @return array<int, array<int, array{0: ?string, 1: int}>>
     */
    private function cellValuesFor(array $rowIds): array
    {
        $values = [];
        foreach (array_chunk($rowIds, 1000) as $chunk) {
            $cells = SheetCell::query()->toBase()->whereIn('row_id', $chunk)->get(['row_id', 'column_id', 'value', 'version']);
            foreach ($cells as $cell) {
                $values[(int) $cell->row_id][(int) $cell->column_id] = [$cell->value, (int) $cell->version];
            }
        }

        return $values;
    }

    private function refreshData(): void
    {
        $this->keepTable = false; // data changed: the table island must be rendered again
        unset($this->columns, $this->sheetProjects, $this->visibleProjects, $this->currentSheetProject, $this->rows, $this->cellValues, $this->sheet, $this->tableData);
    }

    /**
     * Changes when open grids need the whole table again: columns, which rows this view shows (and their
     * order), stages, comments, the deadline. Changes inside rows (values, reviews, names, project, notes)
     * travel through changesSince as cell values and row patches, so reviewing records one by one does not
     * make every open grid reload a big table.
     */
    public function structureSignature(): string
    {
        $parts = [
            SheetColumn::where('sheet_id', $this->sheetId)->selectRaw('count(*) as c, max(updated_at) as u')->first()?->toArray(),
            md5(implode(',', $this->rowsQuery()->pluck('id')->all())),
            SheetProject::where('sheet_id', $this->sheetId)->selectRaw('count(*) as c, max(updated_at) as u')->first()?->toArray(),
            ListComment::whereIn('sheet_project_id', SheetProject::where('sheet_id', $this->sheetId)->select('id'))
                ->selectRaw('count(*) as c, max(updated_at) as u')->first()?->toArray(),
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

        // Rows changed by others (review, names, project…), unless the client reloads the table anyway.
        $current = $this->structureSignature();
        $rows = [];
        if ($current === $signature) {
            $changed = $this->rowsQuery()
                ->where(fn ($q) => $q->where('updated_at', '>=', $from)->orWhereIn('id', ChangeLog::where('sheet_id', $this->sheetId)
                    ->where('created_at', '>=', $from)
                    ->whereIn('action', ['note.create', 'note.delete']) // notes do not touch the row itself
                    ->select('row_id')))
                ->pluck('id')
                ->all();
            $rows = $changed === [] ? [] : ($this->rowsHtml($changed) ?? []);
        }

        return ['now' => $now->toIso8601String(), 'cells' => $cells, 'rows' => $rows, 'signature' => $current];
    }

    // ---------------------------------------------------------------- filters

    public function filterProject($projectId): void
    {
        $user = $this->user();
        if ($projectId === 'none') {
            if (! $user->hasAllProjects()) {
                return;
            }
            $this->projectFilter = 'none';
        } elseif (is_numeric($projectId)) {
            if (! $this->access()->canViewProject($user, (int) $projectId)) {
                return;
            }
            $this->projectFilter = (int) $projectId;
        } else {
            $this->projectFilter = null;
        }
        $this->refreshData();
    }

    // ---------------------------------------------------------------- columns (manager)

    public function openColumn(?int $columnId = null): void
    {
        $this->keepTable = true;
        $this->authorizeManage();
        $this->resetModal();

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
        $this->resetModal();
        $this->refreshData();
    }

    public function deleteColumn(SheetEditor $editor): void
    {
        if (! $this->targetId) {
            return;
        }
        $editor->deleteColumn($this->user(), $this->findColumn($this->targetId));
        $this->resetModal();
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

    /** Drag & drop in the header sends the whole new order. */
    public function reorderColumns(SheetEditor $editor, array $columnIds): void
    {
        $editor->reorderColumns($this->user(), $this->sheet, $columnIds);
        $this->refreshData();
        $this->flash('ترتیب ستون‌ها ذخیره شد.');
    }

    // ---------------------------------------------------------------- rows (manager)

    public function openRow(): void
    {
        $this->keepTable = true;
        $this->authorizeManage();
        $this->resetModal();
        $this->newRow = ['first_name' => '', 'last_name' => '', 'personnel_code' => '', 'national_code' => '', 'project_id' => $this->currentProjectId()];
        $this->modal = 'row';
    }

    public function saveRow(SheetEditor $editor): void
    {
        $editor->addRow($this->user(), $this->sheet, $this->newRow);
        $this->resetModal();
        $this->refreshData();
        $this->flash('ردیف اضافه شد.');
    }

    public function deleteRow(SheetEditor $editor, int $rowId): void
    {
        $editor->deleteRow($this->user(), $this->findRow($rowId));
        $this->refreshData();
        $this->flash('ردیف حذف شد.');
    }

    /** Multi-select: delete the chosen rows. Returns true so the grid script can clear the selection. */
    public function deleteRows(SheetEditor $editor, array $rowIds): bool
    {
        $count = $editor->deleteRows($this->user(), $this->sheet, $rowIds);
        $this->refreshData();
        $this->flash(Digits::toPersian($count).' ردیف حذف شد.');

        return true;
    }

    /** Multi-select: give the chosen rows one project ('' = no project). */
    public function setRowsProject(SheetEditor $editor, array $rowIds, $projectId = null): bool
    {
        $count = $editor->setRowsProject($this->user(), $this->sheet, $rowIds, is_numeric($projectId) ? (int) $projectId : null);
        $this->refreshData();
        $this->flash('پروژه‌ی '.Digits::toPersian($count).' ردیف تغییر کرد.');

        return true;
    }

    public function setRowProject(SheetEditor $editor, int $rowId, $projectId = null): void
    {
        $editor->setRowProject($this->user(), $this->findRow($rowId), is_numeric($projectId) ? (int) $projectId : null);
        $this->patchRows([$rowId]);
    }

    // ---------------------------------------------------------------- review & notes

    public function approveRow(ReviewService $reviews, int $rowId): void
    {
        $row = $this->findRow($rowId);
        $status = $row->review_status === ReviewStatus::Approved ? ReviewStatus::Pending : ReviewStatus::Approved;
        $reviews->review($this->user(), $row, $status);
        $this->patchRows([$row->id]);
    }

    public function openReject(int $rowId): void
    {
        $this->keepTable = true;
        $row = $this->findRow($rowId);
        $this->resetModal();
        $this->targetId = $row->id;
        $this->modal = 'reject';
    }

    public function confirmReject(ReviewService $reviews): void
    {
        $row = $this->findRow((int) $this->targetId);
        $stage = $this->stageOf($row);
        $reviews->review($this->user(), $row, ReviewStatus::Rejected, $this->rejectNote);
        $this->resetModal();
        // A rejection can send the whole list back a stage, which changes what everyone may edit.
        $this->stageOf($row) === $stage ? $this->patchRows([$row->id]) : $this->refreshData();
        $this->flash('رکورد رد شد و یادداشت برای پروژه ثبت شد.');
    }

    public function openNotes(int $rowId): void
    {
        $this->keepTable = true;
        $row = $this->findRow($rowId);
        $this->resetModal();
        $this->targetId = $row->id;
        $this->modal = 'notes';
    }

    public function addNote(ReviewService $reviews): void
    {
        $row = $this->findRow((int) $this->targetId);
        $reviews->addNote($this->user(), $row, $this->newNote);
        $this->newNote = '';
        $this->patchRows([$row->id]);
    }

    // ---------------------------------------------------------------- list comments

    public function addComment(ListHistory $history): void
    {
        $this->keepTable = true;
        $this->resetErrorBag('commentBody');
        $sheetProject = $this->currentSheetProject;
        abort_unless($sheetProject, 404);
        $history->addComment($this->user(), $sheetProject, $this->commentBody, $this->commentInPrint);
        $this->reset(['commentBody', 'commentInPrint']);
        $this->flash('کامنت ثبت شد.');
    }

    public function setCommentPrint(ListHistory $history, int $commentId, bool $inPrint): void
    {
        $this->keepTable = true;
        $history->setCommentPrint($this->user(), $this->findComment($commentId), $inPrint);
    }

    public function deleteComment(ListHistory $history, int $commentId): void
    {
        $this->keepTable = true;
        $history->deleteComment($this->user(), $this->findComment($commentId));
        $this->flash('کامنت حذف شد.');
    }

    private function findComment(int $commentId): ListComment
    {
        return ListComment::whereKey($commentId)
            ->whereIn('sheet_project_id', SheetProject::where('sheet_id', $this->sheetId)->select('id'))
            ->firstOrFail();
    }

    public function deleteNote(ReviewService $reviews, int $noteId): void
    {
        $row = $this->findRow((int) $this->targetId);
        $reviews->deleteNote($this->user(), Note::where('row_id', $row->id)->findOrFail($noteId));
        $this->patchRows([$row->id]);
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
        $this->keepTable = true;
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
        $this->keepTable = true;
        if ($this->targetId) {
            $this->startApproval($approvals, $this->targetId);
        }
    }

    public function confirmApproval(ApprovalService $approvals): void
    {
        $challenge = OtpChallenge::where('user_id', $this->user()->id)->findOrFail((int) $this->otpChallengeId);
        $approval = $approvals->confirm($this->user(), $challenge, $this->otpCode, request()->ip(), request()->userAgent());

        $this->resetModal();
        $this->refreshData();
        $this->flash($approval->stage->actionLabel().' ثبت شد.');
    }

    public function openReopen(int $sheetProjectId): void
    {
        $this->keepTable = true;
        $sheetProject = $this->findSheetProject($sheetProjectId);
        $this->resetModal();
        $this->targetId = $sheetProject->id;
        $this->modal = 'reopen';
    }

    public function confirmReopen(ApprovalService $approvals): void
    {
        $approvals->reopen($this->user(), $this->findSheetProject((int) $this->targetId), trim($this->reopenReason) ?: null);
        $this->resetModal();
        $this->refreshData();
        $this->flash('لیست بازگشایی شد و برای اصلاح به پروژه برگشت.');
    }

    // ---------------------------------------------------------------- month settings (manager)

    public function openProjects(): void
    {
        $this->keepTable = true;
        $this->authorizeManage();
        $this->resetModal();
        $this->monthProjectIds = $this->sheetProjects->keys()->map(fn ($id) => (string) $id)->values()->all();
        $this->modal = 'projects';
    }

    public function saveProjects(SheetEditor $editor): void
    {
        $editor->setMonthProjects($this->user(), $this->sheet, $this->monthProjectIds);
        $this->resetModal();
        $this->refreshData();
        $this->flash('پروژه‌های این ماه به‌روز شد.');
    }

    public function openDeadline(): void
    {
        $this->keepTable = true;
        $this->authorizeManage();
        $this->resetModal();
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
        $this->resetModal();
        $this->refreshData();
        $this->flash('مهلت تکمیل تغییر کرد.');
    }

    public function openImport(PersonnelImporter $importer): void
    {
        $this->keepTable = true;
        abort_unless($importer->mode($this->user(), $this->sheet) !== null, 403);
        $this->resetModal();
        $this->modal = 'import';
    }

    public function import(PersonnelImporter $importer): void
    {
        abort_unless($importer->mode($this->user(), $this->sheet) !== null, 403);
        $this->resetErrorBag(['importFile', 'importRows']); // messages of the previous file
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
        $this->keepTable = true;
        $this->resetModal();
    }

    /** Closes the dialog and clears its fields (actions that change data call this, then refreshData). */
    private function resetModal(): void
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

    /**
     * Column totals of the shown rows.
     *
     * @param  array<int, array<int, array{0: ?string, 1: int}>>  $cells
     * @return array<int, string>
     */
    private function totals(array $cells, Collection $columns): array
    {
        $values = array_fill_keys($columns->filter(fn (SheetColumn $c) => $c->isNumber())->pluck('id')->all(), []);
        foreach ($cells as $rowCells) {
            foreach ($rowCells as $columnId => [$value]) {
                if (isset($values[$columnId]) && $value !== null) {
                    $values[$columnId][] = $value;
                }
            }
        }

        return array_map(fn (array $list) => Digits::sum($list), $values);
    }

    /** @param  array<int, array<int, array{0: ?string, 1: int}>>  $cells */
    private function gridRows(array $cells): GridRows
    {
        $user = $this->user();
        $access = $this->access();

        return new GridRows($user, $access, $this->sheet, $this->columns, $this->sheetProjects, $cells, $access->canManage($user), now());
    }

    /**
     * Sends fresh HTML for just these rows (applied by sheet-grid.js) instead of re-rendering the table,
     * which with hundreds of people is a megabyte of HTML. Falls back to the full table when a row is no
     * longer in the current view (filtered out, deleted meanwhile).
     *
     * @param  list<int>  $rowIds
     */
    private function patchRows(array $rowIds): void
    {
        $html = $this->rowsHtml($rowIds);
        if ($html === null) {
            $this->refreshData();

            return;
        }

        $this->keepTable = true;
        $this->dispatch('grid-rows', rows: $html);
    }

    /**
     * row id => row HTML at its place in the current view, or null when one of them is not in the view.
     *
     * @param  list<int>  $rowIds
     * @return array<int, string>|null
     */
    private function rowsHtml(array $rowIds): ?array
    {
        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        $positions = array_flip($this->rowsQuery()->pluck('id')->all());
        $rows = SheetRow::where('sheet_id', $this->sheetId)->whereIn('id', $rowIds)->withCount('notes')->get();

        if ($rows->count() !== count($rowIds) || $rows->contains(fn (SheetRow $row) => ! isset($positions[$row->id]))) {
            return null;
        }

        $renderer = $this->gridRows($this->cellValuesFor($rowIds));
        $html = [];
        foreach ($rows as $row) {
            $html[$row->id] = $renderer->row($row, $positions[$row->id]);
        }

        return $html;
    }

    private function stageOf(SheetRow $row): ?int
    {
        if (! $row->project_id) {
            return null;
        }
        $stage = SheetProject::where('sheet_id', $this->sheetId)->where('project_id', $row->project_id)->toBase()->value('stage');

        return $stage === null ? null : (int) $stage;
    }

    /** Re-render the table island after every render, unless the action said the table did not change. */
    public function rendered(): void
    {
        if (! $this->keepTable && ! $this->islandIsMounting()) {
            $this->renderIsland('table');
        }
    }

    /**
     * Everything the table island needs (an island only sees component properties, so it asks for this).
     * Computed, so it cannot be called from the browser.
     */
    #[Computed]
    public function tableData(): array
    {
        $user = $this->user();
        $columns = $this->columns;
        $cells = $this->cellValues;

        return [
            'rows' => $this->rows,
            'renderer' => $this->gridRows($cells),
            'columns' => $columns,
            'sheetProjects' => $this->sheetProjects,
            'isManager' => $this->access()->canManage($user),
            'showReview' => $user->isManager() || $user->isGlobalApprover() || $user->isFinance(),
            'totals' => $this->totals($cells, $columns),
        ];
    }

    public function render()
    {
        $user = $this->user();
        $access = $this->access();
        $sheet = $this->sheet;
        $current = $this->currentSheetProject;

        $approvals = collect();
        $currentHash = null;
        if ($current) {
            $approvals = $current->approvals()->with('user')->get();
            if ($approvals->whereNull('revoked_at')->isNotEmpty()) {
                $currentHash = app(ApprovalService::class)->dataHash($current);
            }
        }

        // Approval timeline and comments of the selected project's list (shown under the grid).
        $timeline = [];
        $listComments = collect();
        if ($current) {
            $history = app(ListHistory::class);
            $timeline = $history->timelines($sheet, collect([$current]), $currentHash ? [$current->id => $currentHash] : [])[$current->id];
            $listComments = $history->comments(collect([$current]))[$current->id];
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
            'columns' => $this->columns,
            'sheetProjects' => $this->sheetProjects,
            'isManager' => $access->canManage($user),
            'importMode' => app(PersonnelImporter::class)->mode($user, $sheet),
            'currentSp' => $current,
            'approvalTarget' => $current ? $access->approvalTarget($user, $current) : null,
            'canReopen' => $current !== null && $access->canReopen($user, $current),
            'canSubmit' => $current !== null && $access->canSubmit($user, $sheet, $current),
            'approvals' => $approvals,
            'currentHash' => $currentHash,
            'timeline' => $timeline,
            'listComments' => $listComments,
            'canComment' => $current !== null && $access->canComment($user, $current),
            'unassignedCount' => $access->canManage($user) ? SheetRow::where('sheet_id', $this->sheetId)->whereNull('project_id')->count() : 0,
            'gridConfig' => [
                'poll' => 12,
                'syncedAt' => now()->toIso8601String(),
                'signature' => $this->structureSignature(),
            ],
            'draft' => Stage::Draft,
            ...$modalData,
        ])->title('لیست حقوق '.$sheet->title());
    }
}
