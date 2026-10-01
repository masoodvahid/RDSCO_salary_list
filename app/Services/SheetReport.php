<?php

namespace App\Services;

use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Read model shared by the Excel export and the print (PDF) view.
 */
final class SheetReport
{
    public function __construct(private readonly SheetAccess $access) {}

    /**
     * @return array{
     *   columns: \Illuminate\Support\Collection<int, SheetColumn>,
     *   rows: \Illuminate\Support\Collection<int, \App\Models\SheetRow>,
     *   cells: array<int, array<int, string|null>>,
     *   totals: array<int, string>,
     *   sheetProjects: \Illuminate\Support\Collection<int, SheetProject>
     * }
     */
    public function build(User $user, Sheet $sheet, ?int $projectId = null): array
    {
        if (! $this->access->canView($user) || ($projectId !== null && ! $this->access->canViewProject($user, $projectId))) {
            throw new AuthorizationException;
        }

        $columns = SheetColumn::where('sheet_id', $sheet->id)->orderBy('position')->orderBy('id')->get();
        $rows = $this->access->visibleRows($user, $sheet, $projectId)
            ->with('project')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $cells = [];
        SheetCell::whereIn('row_id', $rows->pluck('id'))
            ->get(['row_id', 'column_id', 'value'])
            ->each(function ($cell) use (&$cells) {
                $cells[$cell->row_id][$cell->column_id] = $cell->value;
            });

        $totals = [];
        foreach ($columns as $column) {
            if (! $column->isNumber()) {
                continue;
            }
            $sum = '0';
            foreach ($rows as $row) {
                $value = $cells[$row->id][$column->id] ?? null;
                if ($value !== null && Digits::normalizeNumber($value) !== false) {
                    $sum = function_exists('bcadd') ? bcadd($sum, $value, 2) : (string) ((float) $sum + (float) $value);
                }
            }
            $totals[$column->id] = Digits::normalizeNumber($sum) ?: '0';
        }

        $scope = $this->access->projectScope($user);
        $sheetProjects = SheetProject::where('sheet_id', $sheet->id)
            ->when($scope !== null, fn ($q) => $q->whereIn('project_id', $scope))
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->with(['project', 'approvals.user'])
            ->get()
            ->sortBy(fn ($sp) => $sp->project->name)
            ->values();

        return compact('columns', 'rows', 'cells', 'totals', 'sheetProjects');
    }
}
