<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Services\SheetExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __invoke(Request $request, Sheet $sheet, SheetExporter $exporter): BinaryFileResponse
    {
        $projectId = $request->filled('project') ? (int) $request->query('project') : null;
        $path = $exporter->toXlsx($request->user(), $sheet, $projectId);

        return response()->download($path, $exporter->filename($sheet, $projectId))->deleteFileAfterSend();
    }
}
