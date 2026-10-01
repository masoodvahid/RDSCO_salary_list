<?php

namespace Tests\Feature;

use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\User;
use App\Services\PrintLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class PrintTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
    }

    private function fill(SheetColumn $column, array $values): void
    {
        foreach ($values as $rowId => $value) {
            SheetCell::create(['row_id' => $rowId, 'column_id' => $column->id, 'value' => $value, 'version' => 1]);
        }
    }

    public function test_columns_empty_or_zero_for_everyone_in_the_report_are_left_out(): void
    {
        $this->fill($this->openColumn, [$this->rowA->id => '0', $this->rowB->id => '0.00']);
        $this->fill($this->lockedColumn, [$this->rowA->id => '150000000']);
        // Project A's report: the text column is filled only for project B.
        $this->fill($this->textColumn, [$this->rowB->id => 'فقط سپهر']);

        $this->actingAs($this->manager);
        $page = $this->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectA->id]))->assertOk();
        $page->assertSee('<th>حقوق پایه</th>', false)
            ->assertDontSee('<th>اضافه‌کار</th>', false)
            ->assertDontSee('<th>توضیحات</th>', false)
            ->assertSee('ستون‌هایی که در این گزارش برای همه خالی یا صفر بودند چاپ نشده‌اند: «اضافه‌کار»، «توضیحات».')
            ->assertSee('150,000,000');

        // The whole list: the text column has a value now.
        $this->get(route('sheets.print', ['sheet' => $this->sheet]))
            ->assertSee('<th>توضیحات</th>', false)
            ->assertDontSee('<th>اضافه‌کار</th>', false);

        // On request, empty columns are printed too.
        $this->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectA->id, 'empty' => 'show']))
            ->assertSee('<th>اضافه‌کار</th>', false)
            ->assertDontSee('چاپ نشده‌اند');
    }

    public function test_a_wide_list_is_split_into_parts_that_repeat_the_names(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $column = SheetColumn::create(['sheet_id' => $this->sheet->id, 'title' => "مبلغ شماره {$i}", 'type' => 'number', 'is_locked' => false, 'position' => 10 + $i]);
            $this->fill($column, [$this->rowA->id => '1250000000']);
        }

        $this->actingAs($this->manager);
        $html = $this->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectA->id]))
            ->assertOk()
            ->assertSee('size: A4 landscape', false)
            ->assertSee('بخش ۲ از')
            ->getContent();

        $parts = substr_count($html, 'class="print-part"');
        $this->assertGreaterThan(1, $parts);
        // Every part repeats ردیف / نام / نام خانوادگی and has its own totals row; the review column is printed once.
        $this->assertSame($parts, substr_count($html, '<th>نام خانوادگی</th>'));
        $this->assertSame($parts, substr_count($html, '<tr class="totals">'));
        $this->assertSame(1, substr_count($html, '<th>بررسی</th>'));
        $this->assertSame(1, substr_count($html, '<th>کد ملی</th>'));
        $this->assertSame(1, substr_count($html, '<th>مبلغ شماره 40</th>'));

        // A3 holds more columns per page.
        $a3 = substr_count($this->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectA->id, 'paper' => 'A3']))
            ->assertSee('size: A3 landscape', false)->getContent(), 'class="print-part"');
        $this->assertLessThan($parts, $a3);

        // Unknown values fall back to the defaults.
        $this->get(route('sheets.print', ['sheet' => $this->sheet, 'paper' => 'B5', 'orientation' => 'sideways']))
            ->assertSee('size: A4 landscape', false);
    }

    public function test_the_title_names_the_projects_the_report_covers(): void
    {
        $editor = User::factory()->editor($this->projectA, $this->projectB)->create();
        $this->actingAs($editor)->get(route('sheets.print', ['sheet' => $this->sheet]))
            ->assertOk()
            ->assertSee('پروژه‌های دماوند، سپهر')
            ->assertDontSee('همه پروژه‌ها');

        $this->actingAs($this->manager)->get(route('sheets.print', ['sheet' => $this->sheet]))->assertSee('همه پروژه‌ها');
        $this->actingAs($this->manager)->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectB->id]))->assertSee('پروژه سپهر');
    }

    public function test_layout_keeps_every_column_once_and_in_order(): void
    {
        $columns = collect();
        for ($i = 1; $i <= 25; $i++) {
            $columns->push(SheetColumn::create(['sheet_id' => $this->sheet->id, 'title' => "ستون {$i}", 'type' => 'number', 'is_locked' => false, 'position' => 10 + $i]));
        }
        $rows = collect([$this->rowA->load('project'), $this->rowB->load('project')]);

        foreach (['A4' => 'portrait', 'A3' => 'landscape'] as $paper => $orientation) {
            $plan = PrintLayout::plan($columns, $rows, [], $paper, $orientation, ['personnel' => false, 'project' => true]);
            $flat = collect($plan['parts'])->flatMap(fn ($part) => $part['columns'])->pluck('id')->all();
            $this->assertSame($columns->pluck('id')->all(), $flat);
            $this->assertTrue($plan['parts'][0]['first']);
            $this->assertTrue(end($plan['parts'])['last']);
        }
        $this->assertTrue(PrintLayout::isBlank($columns[0], '۰'));
        $this->assertFalse(PrintLayout::isBlank($this->textColumn, '0'));
    }
}
