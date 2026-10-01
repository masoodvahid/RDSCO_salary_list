<?php

namespace App\Services;

use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Support\Digits;
use Illuminate\Support\Collection;

/**
 * Plans the printed list: which columns to leave out (empty for everyone in the report) and how to
 * split a wide list into parts that each fit the page width. Every part repeats ردیف / نام / نام خانوادگی,
 * so the parts can be read (or laid) side by side.
 *
 * Widths are estimated from the text length; the print page also shrinks a part's font a little if
 * the browser still finds it too wide, so the estimate only has to be close.
 */
final class PrintLayout
{
    /** [width, height] in mm */
    public const PAPERS = ['A4' => [210, 297], 'A3' => [297, 420]];

    public const ORIENTATIONS = ['landscape', 'portrait'];

    public const MARGIN_MM = 10;

    private const PX_PER_MM = 96 / 25.4;

    private const CELL_PADDING_PX = 11;

    /** Columns whose value is empty (or zero, for numbers) in every row of the report. */
    public static function emptyColumns(Collection $columns, Collection $rows, array $cells): Collection
    {
        if ($rows->isEmpty()) {
            return collect(); // nothing to judge by: print the blank list with all its columns
        }

        return $columns->filter(function (SheetColumn $column) use ($rows, $cells) {
            foreach ($rows as $row) {
                if (! self::isBlank($column, $cells[$row->id][$column->id] ?? null)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    public static function isBlank(SheetColumn $column, ?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }
        if (! $column->isNumber()) {
            return false;
        }
        $number = Digits::normalizeNumber($value);

        return is_string($number) && Digits::compare($number, '0') === 0;
    }

    /**
     * @param  array{personnel: bool, project: bool}  $identity  optional identity columns shown in the first part
     * @param  array<int, string>  $totals  column id => total, as printed on the totals line
     * @return array{paper: string, orientation: string, pageSize: string, widthMm: int, fontPx: float, parts: list<array{columns: Collection<int, SheetColumn>, first: bool, last: bool}>}
     */
    public static function plan(Collection $columns, Collection $rows, array $cells, string $paper, string $orientation, array $identity, array $totals = []): array
    {
        $paper = array_key_exists($paper, self::PAPERS) ? $paper : 'A4';
        $orientation = in_array($orientation, self::ORIENTATIONS, true) ? $orientation : 'landscape';
        [$short, $long] = self::PAPERS[$paper];
        $widthMm = ($orientation === 'landscape' ? $long : $short) - 2 * self::MARGIN_MM;
        $fontPx = $paper === 'A3' ? 11.0 : 10.0;

        $repeated = self::identityWidth($rows, $fontPx, ['personnel' => false, 'project' => false, 'national' => false]);
        $full = self::identityWidth($rows, $fontPx, $identity + ['national' => true]);
        $columns = $columns->values();
        $widths = $columns->map(function (SheetColumn $column) use ($rows, $cells, $totals, $fontPx) {
            $values = $rows->map(fn (SheetRow $r) => self::display($column, $cells[$r->id][$column->id] ?? null));
            // The bold totals line is usually the widest number of the column.
            $values->push($column->isNumber() ? Digits::group($totals[$column->id] ?? null) : '');

            return self::columnWidth($column->title, $values, $fontPx);
        })->all();

        $pack = function (float $factor) use ($columns, $widths, $widthMm, $full, $repeated): array {
            $available = $widthMm * self::PX_PER_MM * $factor;
            $parts = [];
            $current = [];
            $used = $full;
            foreach ($columns as $i => $column) {
                if ($current !== [] && $used + $widths[$i] > $available) {
                    $parts[] = $current;
                    $current = [];
                    $used = $repeated;
                }
                $current[] = $column;
                $used += $widths[$i];
            }
            $parts[] = $current; // the short review status closes the last part

            return $parts;
        };

        $parts = $pack(0.97);
        // Rather let the page shrink the font a little than print a part with only one or two columns.
        if (count($parts) > 1 && count(end($parts)) <= 2) {
            $tighter = $pack(1.06);
            if (count($tighter) < count($parts)) {
                $parts = $tighter;
            }
        }

        $count = count($parts);

        return [
            'paper' => $paper,
            'orientation' => $orientation,
            'pageSize' => "{$paper} {$orientation}",
            'widthMm' => $widthMm,
            'fontPx' => $fontPx,
            'parts' => array_map(fn ($cols, $i) => ['columns' => collect($cols), 'first' => $i === 0, 'last' => $i === $count - 1], $parts, array_keys($parts)),
        ];
    }

    /** The value as printed. */
    public static function display(SheetColumn $column, ?string $value): string
    {
        return $column->isNumber() ? Digits::group($value) : (string) $value;
    }

    /** @param array{personnel: bool, project: bool, national: bool} $with */
    private static function identityWidth(Collection $rows, float $fontPx, array $with): float
    {
        $width = self::columnWidth('ردیف', collect([(string) $rows->count()]), $fontPx)
            + self::columnWidth('نام', $rows->pluck('first_name'), $fontPx)
            + self::columnWidth('نام خانوادگی', $rows->pluck('last_name'), $fontPx);
        if ($with['personnel']) {
            $width += self::columnWidth('کد پرسنلی', $rows->pluck('personnel_code'), $fontPx);
        }
        if ($with['national']) {
            $width += self::columnWidth('کد ملی', collect(['0000000000']), $fontPx);
        }
        if ($with['project']) {
            $width += self::columnWidth('پروژه', $rows->map(fn (SheetRow $r) => $r->project?->name), $fontPx);
        }

        return $width;
    }

    /**
     * Header text may wrap onto up to three lines; values stay on one line.
     *
     * @param  Collection<int, string|null>  $values
     */
    private static function columnWidth(string $title, Collection $values, float $fontPx): float
    {
        $words = preg_split('/\s+/u', trim($title)) ?: [$title];
        $header = max(
            max(array_map(fn ($word) => self::textWidth($word, $fontPx, true), $words)),
            self::textWidth($title, $fontPx, true) / 3,
        );
        $value = (float) $values->map(fn ($v) => self::textWidth((string) $v, $fontPx, false))->max();

        return max($header, $value * 1.04, 3 * $fontPx) + self::CELL_PADDING_PX;
    }

    /** Rough rendered width: tabular digits are wide, Persian letters narrow, separators thin. */
    private static function textWidth(string $text, float $fontPx, bool $bold): float
    {
        $em = 0.0;
        foreach (mb_str_split($text) as $char) {
            $em += match (true) {
                $char === "\u{200C}" => 0.0,
                (bool) preg_match('/^[0-9۰-۹٠-٩]$/u', $char) => 0.68,
                in_array($char, [',', '.', '٬', '٫', '/', '-', ' ', '(', ')'], true) => 0.3,
                (bool) preg_match('/^[A-Za-z]$/', $char) => 0.58,
                default => 0.5,
            };
        }

        return $em * $fontPx * ($bold ? 1.08 : 1.0);
    }
}
