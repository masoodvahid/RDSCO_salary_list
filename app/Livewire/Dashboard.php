<?php

namespace App\Livewire;

use App\Enums\ReviewStatus;
use App\Enums\Stage;
use App\Models\ChangeLog;
use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Services\SheetAccess;
use App\Services\SheetLifecycle;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class Dashboard extends Component
{
    #[Url(as: 'sheet')]
    public ?int $sheetId = null;

    /** Open dialog: move | delete (managers). */
    public ?string $modal = null;

    public $moveYear = 0;

    public $moveMonth = 1;

    public ?string $notice = null;

    #[Computed]
    public function sheets()
    {
        return Sheet::orderByDesc('jalali_year')->orderByDesc('jalali_month')->get();
    }

    #[Computed]
    public function sheet(): ?Sheet
    {
        return $this->sheetId ? $this->sheets->firstWhere('id', $this->sheetId) : $this->sheets->first();
    }

    /** Per project of the month: stage, rows, fill %, rejected rows. */
    #[Computed]
    public function projects()
    {
        $sheet = $this->sheet;
        if (! $sheet) {
            return collect();
        }

        $access = app(SheetAccess::class);
        $scope = $access->projectScope(auth()->user());

        $sheetProjects = SheetProject::where('sheet_id', $sheet->id)
            ->when($scope !== null, fn ($q) => $q->whereIn('project_id', $scope))
            ->with('project')
            ->get()
            ->sortBy(fn ($sp) => $sp->project->name);

        $rowCounts = SheetRow::where('sheet_id', $sheet->id)
            ->select('project_id', DB::raw('count(*) as total'), DB::raw("sum(case when review_status = 'rejected' then 1 else 0 end) as rejected"))
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $filled = SheetCell::query()
            ->join('sheet_rows', 'sheet_rows.id', '=', 'sheet_cells.row_id')
            ->where('sheet_rows.sheet_id', $sheet->id)
            ->whereNotNull('sheet_cells.value')
            ->select('sheet_rows.project_id', DB::raw('count(*) as filled'))
            ->groupBy('sheet_rows.project_id')
            ->pluck('filled', 'sheet_rows.project_id');

        $columnCount = max(1, SheetColumn::where('sheet_id', $sheet->id)->count());

        return $sheetProjects->map(function (SheetProject $sp) use ($rowCounts, $filled, $columnCount) {
            $rows = (int) ($rowCounts->get($sp->project_id)?->total ?? 0);
            $cells = (int) ($filled[$sp->project_id] ?? 0);

            return [
                'model' => $sp,
                'rows' => $rows,
                'rejected' => (int) ($rowCounts->get($sp->project_id)?->rejected ?? 0),
                'progress' => $rows ? min(100, (int) round($cells / ($rows * $columnCount) * 100)) : 0,
            ];
        })->values();
    }

    #[Computed]
    public function unassigned(): int
    {
        return $this->sheet && auth()->user()->hasAllProjects()
            ? SheetRow::where('sheet_id', $this->sheet->id)->whereNull('project_id')->count()
            : 0;
    }

    #[Computed]
    public function activity()
    {
        if (! $this->sheet || ! auth()->user()->hasAllProjects()) {
            return collect();
        }

        return ChangeLog::where('sheet_id', $this->sheet->id)
            ->whereIn('action', ['stage.approve', 'stage.reopen', 'stage.return', 'stage.submit', 'row.review', 'sheet.import', 'sheet.import.values', 'sheet.create', 'sheet.month'])
            ->with('user')
            ->latest('id')
            ->limit(12)
            ->get();
    }

    // ---------------------------------------------------------------- delete / change month (managers)

    public function openMove(): void
    {
        $sheet = $this->managedSheet();
        $this->moveYear = $sheet->jalali_year;
        $this->moveMonth = $sheet->jalali_month;
        $this->resetErrorBag();
        $this->modal = 'move';
    }

    public function saveMove(SheetLifecycle $lifecycle): void
    {
        $sheet = $this->managedSheet();
        $this->resetErrorBag();
        $deadlineMoved = $lifecycle->moveTo(auth()->user(), $sheet, (int) $this->moveYear, (int) $this->moveMonth);

        $sheet->refresh();
        $this->modal = null;
        $this->refreshSheets();
        $this->notice = "ماه لیست به {$sheet->title()} تغییر کرد. "
            .($deadlineMoved ? 'مهلت تکمیل هم به '.Jalali::formatLong($sheet->deadline_at).' منتقل شد.' : 'مهلت تکمیل دستی تعیین شده بود و تغییری نکرد؛ در صورت نیاز آن را اصلاح کنید.');
    }

    public function openDelete(): void
    {
        $this->managedSheet();
        $this->resetErrorBag();
        $this->modal = 'delete';
    }

    public function confirmDelete(SheetLifecycle $lifecycle): void
    {
        $sheet = $this->managedSheet();
        $title = $sheet->title();
        $lifecycle->delete(auth()->user(), $sheet);

        $this->modal = null;
        $this->sheetId = null;
        $this->refreshSheets();
        $this->notice = "لیست حقوق {$title} حذف شد.";
    }

    public function closeModal(): void
    {
        $this->modal = null;
        $this->resetErrorBag();
    }

    public function dismissNotice(): void
    {
        $this->notice = null;
    }

    private function managedSheet(): Sheet
    {
        abort_unless(app(SheetAccess::class)->canManage(auth()->user()), 403);
        $sheet = $this->sheet;
        abort_unless($sheet, 404);

        return $sheet;
    }

    private function refreshSheets(): void
    {
        unset($this->sheets, $this->sheet, $this->projects, $this->unassigned, $this->activity);
    }

    public function stageCounts(): array
    {
        $counts = array_fill_keys(array_map(fn ($s) => $s->value, Stage::cases()), 0);
        foreach ($this->projects as $item) {
            $counts[$item['model']->stage->value]++;
        }

        return $counts;
    }

    public function render()
    {
        $projectIds = $this->activity->map(fn (ChangeLog $log) => $log->meta['project_id'] ?? null)->filter()->unique();

        $lifecycle = app(SheetLifecycle::class);
        $dialog = [];
        if ($this->modal === 'move' && $this->sheet) {
            $dialog['moveBlocker'] = $lifecycle->moveBlocker($this->sheet, (int) $this->moveYear, (int) $this->moveMonth);
            $dialog['months'] = Jalali::monthNames();
        }
        if ($this->modal === 'delete' && $this->sheet) {
            $dialog['deleteBlocker'] = $lifecycle->deleteBlocker($this->sheet);
            $dialog['blockedByPeople'] = $dialog['deleteBlocker'] !== null && ! $lifecycle->isApproved($this->sheet);
        }

        return view('livewire.dashboard', $dialog + [
            'stageCounts' => $this->stageCounts(),
            'rejectedStatus' => ReviewStatus::Rejected,
            'projectNames' => $projectIds->isEmpty() ? collect() : Project::whereIn('id', $projectIds)->pluck('name', 'id'),
        ])->title('لیست‌های حقوق');
    }
}
