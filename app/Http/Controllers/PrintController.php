<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Services\ApprovalService;
use App\Services\ListHistory;
use App\Services\PrintLayout;
use App\Services\SheetReport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Print-friendly page; "Save as PDF" in the browser renders Persian perfectly.
 *
 * Query: project, paper (A4|A3), orientation (landscape|portrait), empty=show to keep empty columns,
 * timeline=0 to leave out the approval timeline, comments=0 to leave out the comments ticked for print.
 */
class PrintController extends Controller
{
    public function __invoke(Request $request, Sheet $sheet, SheetReport $report, ApprovalService $approvals, ListHistory $history): View
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
        // Bottom of the page: approval timeline (unless turned off) and the comments ticked for print.
        $showTimeline = $request->query('timeline', '1') !== '0';
        $showComments = $request->query('comments', '1') !== '0';
        $timelines = $showTimeline ? $history->timelines($sheet, $data['sheetProjects'], $hashes->all()) : [];
        $comments = $showComments ? $history->comments($data['sheetProjects'], printOnly: true) : [];

        return view('sheets.print', $data + [
            'sheet' => $sheet,
            'projectId' => $projectId,
            'projectName' => $projectId ? $data['sheetProjects']->first()?->project->name : null,
            'scopeLabel' => $this->scopeLabel($request, $data['sheetProjects'], $projectId),
            'hashes' => $hashes,
            'showTimeline' => $showTimeline,
            'showComments' => $showComments,
            'timelines' => $timelines,
            'printComments' => $comments,
            'layout' => $layout,
            'identity' => $identity,
            'emptyColumns' => $emptyColumns,
            'showEmpty' => $showEmpty,
        ]);
    }

    /** What the printed list covers, e.g. «پروژه دماوند», «پروژه‌های دماوند، سپهر» or «همه پروژه‌ها». */
    private function scopeLabel(Request $request, Collection $sheetProjects, ?int $projectId): string
    {
        $names = $sheetProjects->pluck('project.name');
        if ($projectId === null && $request->user()->hasAllProjects()) {
            return 'همه پروژه‌ها';
        }

        return ($names->count() === 1 ? 'پروژه ' : 'پروژه‌های ').$names->join('، ');
    }
}
