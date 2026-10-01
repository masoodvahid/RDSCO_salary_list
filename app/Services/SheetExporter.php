<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Sheet;
use App\Models\SheetProject;
use App\Models\User;
use App\Support\Jalali;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds an XLSX of the rows the user can see (RTL, frozen header) plus an approvals sheet.
 * Shares its data source with the print view through SheetReport.
 */
final class SheetExporter
{
    public function __construct(private readonly SheetReport $report) {}

    /** @return string path to a temporary .xlsx file */
    public function toXlsx(User $user, Sheet $sheet, ?int $projectId = null): string
    {
        $data = $this->report->build($user, $sheet, $projectId);
        $base = tempnam(sys_get_temp_dir(), 'tukahr-');
        @unlink($base);
        $path = $base.'.xlsx';
        $bold = (new Style)->withFontBold(true);

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()
            ->setName('لیست حقوق')
            ->setSheetView(new SheetView(rightToLeft: true, freezeRow: 2));

        $header = ['ردیف', 'نام', 'نام خانوادگی', 'کد پرسنلی', 'کد ملی', 'پروژه'];
        foreach ($data['columns'] as $column) {
            $header[] = $column->title;
        }
        $header[] = 'وضعیت بررسی';
        $writer->addRow(Row::fromValuesWithStyle($header, $bold));

        foreach ($data['rows'] as $i => $row) {
            $values = [$i + 1, $row->first_name, $row->last_name, (string) $row->personnel_code, $row->national_code, $row->project?->name ?? ''];
            foreach ($data['columns'] as $column) {
                $value = $data['cells'][$row->id][$column->id] ?? null;
                $values[] = $column->isNumber() && $value !== null && is_numeric($value) ? $value + 0 : ($value ?? '');
            }
            $values[] = $row->review_status->label();
            $writer->addRow(Row::fromValues($values));
        }

        $totals = array_fill(0, 6, '');
        $totals[1] = 'جمع';
        foreach ($data['columns'] as $column) {
            $totals[] = $column->isNumber() ? ($data['totals'][$column->id] ?? 0) + 0 : '';
        }
        $totals[] = '';
        $writer->addRow(Row::fromValuesWithStyle($totals, $bold));

        $writer->addNewSheetAndMakeItCurrent()
            ->setName('تاییدها')
            ->setSheetView(new SheetView(rightToLeft: true));
        $writer->addRow(Row::fromValuesWithStyle(['پروژه', 'وضعیت', 'مرحله', 'تاییدکننده', 'زمان', 'باطل‌شده'], $bold));

        /** @var SheetProject $sheetProject */
        foreach ($data['sheetProjects'] as $sheetProject) {
            /** @var Approval $approval */
            foreach ($sheetProject->approvals as $approval) {
                $writer->addRow(Row::fromValues([
                    $sheetProject->project->name,
                    $sheetProject->stage->label(),
                    $approval->stage->actionLabel(),
                    $approval->user?->nameWithTitle() ?? '',
                    Jalali::formatShort($approval->created_at).' '.$approval->created_at->format('H:i'),
                    $approval->revoked_at ? 'بله' : '',
                ]));
            }
        }

        $writer->close();

        return $path;
    }

    public function filename(Sheet $sheet, ?int $projectId): string
    {
        $suffix = $projectId ? '-p'.$projectId : '';

        return "tukahr-{$sheet->jalali_year}-".str_pad((string) $sheet->jalali_month, 2, '0', STR_PAD_LEFT).$suffix.'.xlsx';
    }
}
