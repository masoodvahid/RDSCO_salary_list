<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Services\ApprovalService;
use App\Services\PrintLayout;
use App\Services\SheetReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Print-friendly page; "Save as PDF" in the browser renders Persian perfectly.
 *
 * Query: project, paper (A4|A3), orientation (landscape|portrait), empty=show to keep empty columns.
 */
class PrintController extends Controller
{
    public function __invoke(Request $request, Sheet $sheet, SheetReport $report, ApprovalService $approvals): View
    {
        $projectId = $request->filled('project') ? (int) $request->query('project') : null;
        $data = $report->build($request->user(), $sheet, $projectId);

        $showEmpty = $request->query('empty') === 'show';
        $emptyColumns = PrintLayout::emptyColumns($data['columns'], $data['rows'], $data['cells']);
        $columns = $showEmpty ? $data['columns'] : $data['columns']->diff($emptyColumns)->values();
        $identity = [
            'personnel' => $data['rows']->contains(fn ($row) => filled($row->personnel_code)),
            // A one-project report names the project in its title.
            'project' => $projectId === null,
        ];
        $layout = PrintLayout::plan($columns, $data['rows'], $data['cells'], (string) $request->query('paper', 'A4'), (string) $request->query('orientation', 'landscape'), $identity, $data['totals']);

        $hashes = $data['sheetProjects']->mapWithKeys(fn ($sp) => [$sp->id => $approvals->dataHash($sp)]);

        return view('sheets.print', $data + [
            'sheet' => $sheet,
            'projectId' => $projectId,
            'projectName' => $projectId ? $data['sheetProjects']->first()?->project->name : null,
            'hashes' => $hashes,
            'layout' => $layout,
            'identity' => $identity,
            'emptyColumns' => $emptyColumns,
            'showEmpty' => $showEmpty,
        ]);
    }
}
