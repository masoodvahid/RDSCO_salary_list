<?php

namespace App\Livewire\Sheets;

use App\Enums\ReviewStatus;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\SheetAccess;
use App\Support\Digits;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The <tr> of each person in the payroll grid, used for the whole table and for single-row updates
 * (approve, notes, project…), so both always produce the same HTML and the same data-hash.
 *
 * Plain PHP rather than a Blade loop: with hundreds of people and ~25 cells each this is the hottest code
 * of the page, and Blade (plus Livewire's morph markers around every @if) cost about five times as much.
 * Everything printed from data goes through e().
 *
 * The rows carry no Alpine or wire: directives (their <tbody> is x-ignore'd): sheet-grid.js handles the
 * buttons (data-act), the selection checkboxes and the project <select> by event delegation.
 */
final class GridRows
{
    /** field => [extra td class, numeric] */
    private const IDENTITY = [
        'first_name' => ['sticky-2', false],
        'last_name' => ['sticky-3', false],
        'personnel_code' => ['', true],
        'national_code' => ['', true],
    ];

    /** @var list<array{id: int, type: string, number: bool, open: bool, range: ?string, model: SheetColumn, c: int, num: string, mode: string}> */
    private array $columns = [];

    /** @var array<int, array{0: bool, 1: bool, 2: bool}> project id (0 = none) => [identity editable, cells open, can review] */
    private array $rights = [];

    /**
     * @param  Collection<int, SheetColumn>  $columns  in grid order
     * @param  Collection<int, SheetProject>  $sheetProjects  keyed by project id, with project loaded
     * @param  array<int, array<int, array{0: ?string, 1: int}>>  $cells  row id => column id => [value, version]
     */
    public function __construct(
        private readonly User $user,
        private readonly SheetAccess $access,
        private readonly Sheet $sheet,
        Collection $columns,
        private readonly Collection $sheetProjects,
        private readonly array $cells,
        private readonly bool $isManager,
        private readonly CarbonInterface $now,
    ) {
        foreach ($columns->values() as $i => $column) {
            $number = $column->isNumber();
            $this->columns[] = [
                'id' => (int) $column->id,
                'type' => e($column->type->value),
                'number' => $number,
                'open' => $isManager || ! $column->is_locked,
                'range' => $column->rangeLabel(),
                'model' => $column,
                'c' => 4 + $i,
                'num' => $number ? ' num text-left' : '',
                'mode' => $number ? ' inputmode="decimal"' : '',
            ];
        }
    }

    /** @param  iterable<int, SheetRow>  $rows  in grid order */
    public function rows(iterable $rows): string
    {
        $html = '';
        $index = 0;
        foreach ($rows as $row) {
            $html .= $this->row($row, $index++);
        }

        return $html;
    }

    /** One person's row; $index is its position in the current view (row number - 1). */
    public function row(SheetRow $row, int $index): string
    {
        $rid = (int) $row->id;
        $number = Digits::toPersian($index + 1);
        $name = e($row->fullName());
        $sheetProject = $row->project_id ? $this->sheetProjects->get($row->project_id) : null;
        [$identityEditable, $rowOpen, $canReview] = $this->rights($row, $sheetProject);
        $status = $row->review_status;

        // # (with the selection checkbox for managers)
        if ($this->isManager) {
            $html = '<td id="n'.$rid.'" class="sticky-1 ro text-xs text-ink-soft"><label class="row-select"'
                .($identityEditable ? '' : ' title="لیست این پروژه قفل است (تایید مدیرعامل یا نهایی)"')
                .'><input type="checkbox" data-select-row="'.$rid.'"'.($identityEditable ? '' : ' disabled')
                .' aria-label="انتخاب '.$name.'"><span>'.$number.'</span></label></td>';
        } else {
            $html = '<td id="n'.$rid.'" class="sticky-1 ro text-center text-xs text-ink-soft">'.$number.'</td>';
        }

        // Personnel fields (first and last name label the row's inputs through their ids)
        $c = 0;
        foreach (self::IDENTITY as $field => [$sticky, $numeric]) {
            $value = e($row->{$field});
            $id = match ($field) {
                'first_name' => ' id="f'.$rid.'"',
                'last_name' => ' id="l'.$rid.'"',
                default => '',
            };
            $num = $numeric ? ' num text-left' : '';
            if ($identityEditable) {
                $html .= '<td'.$id.($sticky !== '' ? ' class="'.$sticky.'"' : '').'><input data-cell data-row="'.$rid.'" data-field="'.$field
                    .'" data-r="'.$index.'" data-c="'.$c.'" data-saved="'.$value.'" value="'.$value
                    .'" autocomplete="off" aria-labelledby="h-'.$field.' n'.$rid.'" class="cell'.$num.'"></td>';
            } else {
                $html .= '<td'.$id.' class="ro'.($sticky !== '' ? ' '.$sticky : '').'"><span class="cell-text'.$num.'">'.$value.'</span></td>';
            }
            $c++;
        }

        // Project
        if ($identityEditable) {
            $html .= '<td><select class="cell" data-project-select data-value="'.e($row->project_id).'" aria-label="پروژه · '.$name.'">'
                .($sheetProject
                    ? '<option value="'.e($row->project_id).'" selected>'.e($sheetProject->project->name).'</option>'
                    : '<option value="" selected>— انتخاب پروژه</option>')
                .'</select></td>';
        } else {
            $html .= '<td class="ro"><span class="cell-text">'.e($sheetProject?->project->name ?? '—').'</span></td>';
        }

        // Payroll cells
        $cells = $this->cells[$rid] ?? [];
        foreach ($this->columns as $column) {
            [$value, $version] = $cells[$column['id']] ?? [null, 0];
            $out = $column['range'] !== null && $column['model']->isOutOfRange($value);
            $class = $column['num'].($out ? ' is-out-of-range' : '');
            $shown = e($column['number'] ? Digits::group($value) : $value);
            $outTitle = $out ? ' title="خارج از بازه مجاز ('.e($column['range']).')"' : '';
            if ($rowOpen && $column['open']) {
                $html .= '<td><input data-cell data-row="'.$rid.'" data-col="'.$column['id'].'" data-type="'.$column['type']
                    .'" data-version="'.(int) $version.'" data-saved="'.e($value).'" data-r="'.$index.'" data-c="'.$column['c']
                    .'" value="'.$shown.'" autocomplete="off"'.$column['mode'].' aria-labelledby="h'.$column['id'].' f'.$rid.' l'.$rid.'"'
                    .$outTitle.' class="cell'.$class.'"></td>';
            } else {
                $title = $outTitle !== '' ? $outTitle : (! $column['number'] && $value !== null ? ' title="'.e($value).'"' : '');
                $html .= '<td class="ro"><span class="cell-text'.$class.'"'.$title.'>'.$shown.'</span></td>';
            }
        }

        // Record review
        $html .= '<td class="px-1.5">';
        if ($canReview) {
            $approved = $status === ReviewStatus::Approved;
            $html .= '<div class="flex items-center gap-1"><button type="button" data-act="approve" aria-label="تایید رکورد '.$name
                .'" aria-pressed="'.($approved ? 'true' : 'false').'" class="review-btn is-approve'.($approved ? ' is-on' : '').'">✓</button>'
                .'<button type="button" data-act="reject" aria-label="رد رکورد '.$name.'" class="review-btn is-reject'
                .($status === ReviewStatus::Rejected ? ' is-on' : '').'">✕</button></div>';
        } else {
            $tone = match ($status) {
                ReviewStatus::Approved => ' font-semibold text-emerald-700',
                ReviewStatus::Rejected => ' font-semibold text-red-700',
                default => ' text-ink-soft',
            };
            $html .= '<span class="cell-text text-xs'.$tone.'">'.e($status->label()).'</span>';
        }
        $html .= '</td>';

        // Notes (and delete for managers)
        $notes = (int) $row->notes_count;
        $html .= '<td class="px-1.5"><div class="flex items-center justify-center gap-1"><button type="button" data-act="notes" aria-label="یادداشت‌های '.$name
            .'" class="note-btn'.($notes ? ' has-notes' : '').'"><svg class="size-3.5" aria-hidden="true"><use href="#i-note"/></svg>'
            .($notes ? Digits::toPersian($notes) : '').'</button>';
        if ($identityEditable) {
            $html .= '<button type="button" data-act="delete" data-confirm="ردیف «'.$name.'» حذف شود؟ این کار در لاگ ثبت می‌شود." class="row-del" aria-label="حذف ردیف '.$name
                .'"><svg class="size-3.5" aria-hidden="true"><use href="#i-trash"/></svg></button>';
        }
        $html .= '</div></td>';

        $class = $status === ReviewStatus::Rejected ? ' class="is-rejected"' : '';

        return '<tr wire:key="row-'.$rid.'" data-r="'.$index.'" data-row-id="'.$rid.'" data-hash="'.hash('xxh3', $index.$class.$html).'"'.$class.'>'.$html."</tr>\n";
    }

    /**
     * Edit rights depend on the row's project only (stage, membership, deadline), so they are worked out
     * once per project instead of once per row.
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private function rights(SheetRow $row, ?SheetProject $sheetProject): array
    {
        return $this->rights[(int) ($row->project_id ?? 0)] ??= [
            $this->access->canEditIdentity($this->user, $sheetProject),
            $this->access->canEditRowCells($this->user, $this->sheet, $row, $sheetProject, $this->now),
            $this->access->canReview($this->user, $sheetProject),
        ];
    }
}
