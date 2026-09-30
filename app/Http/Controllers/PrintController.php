<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Services\ApprovalService;
use App\Services\SheetReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Print-friendly page; "Save as PDF" in the browser renders Persian perfectly.
 */
class PrintController extends Controller
{
    public function __invoke(Request $request, Sheet $sheet, SheetReport $report, ApprovalService $approvals): View
    {
        $projectId = $request->filled('project') ? (int) $request->query('project') : null;
        $data = $report->build($request->user(), $sheet, $projectId);

        $hashes = $data['sheetProjects']->mapWithKeys(fn ($sp) => [$sp->id => $approvals->dataHash($sp)]);

        return view('sheets.print', $data + [
            'sheet' => $sheet,
            'projectName' => $projectId ? $data['sheetProjects']->first()?->project->name : null,
            'hashes' => $hashes,
        ]);
    }
}
