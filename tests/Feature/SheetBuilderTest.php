<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\SheetBuilder;
use App\Support\Jalali;
use App\Support\NationalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SheetBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_month_gets_default_deadline_columns_and_active_projects(): void
    {
        $manager = User::factory()->manager()->create();
        Project::factory()->create();
        Project::factory()->create(['is_active' => false]);

        $sheet = app(SheetBuilder::class)->create($manager, 1405, 6);

        $this->assertSame([1405, 7, 14], Jalali::fromCarbon($sheet->deadline_at));
        $this->assertSame('23:59:59', $sheet->deadline_at->format('H:i:s'));
        // Only the built-in personnel fields; payroll columns come from Excel or are added by hand.
        $this->assertSame(0, SheetColumn::where('sheet_id', $sheet->id)->count());
        $this->assertSame(1, $sheet->sheetProjects()->count());
        $this->assertSame('شهریور ۱۴۰۵', $sheet->title());

        $this->expectException(ValidationException::class);
        app(SheetBuilder::class)->create($manager, 1405, 6);
    }

    public function test_copy_from_previous_month_keeps_structure_and_locked_values(): void
    {
        $manager = User::factory()->manager()->create();
        $project = Project::factory()->create();
        $builder = app(SheetBuilder::class);
        $previous = $builder->create($manager, 1405, 5);

        $locked = SheetColumn::create(['sheet_id' => $previous->id, 'title' => 'حقوق پایه', 'type' => 'number', 'is_locked' => true, 'position' => 1]);
        $open = SheetColumn::create(['sheet_id' => $previous->id, 'title' => 'اضافه‌کار', 'type' => 'number', 'is_locked' => false, 'position' => 2]);
        $row = SheetRow::create([
            'sheet_id' => $previous->id, 'project_id' => $project->id, 'first_name' => 'علی', 'last_name' => 'رضایی',
            'national_code' => NationalCode::fromNineDigits('001245876'), 'position' => 1,
        ]);
        SheetCell::create(['row_id' => $row->id, 'column_id' => $locked->id, 'value' => '150000000', 'version' => 1]);
        SheetCell::create(['row_id' => $row->id, 'column_id' => $open->id, 'value' => '12', 'version' => 1]);

        $next = $builder->create($manager, 1405, 6, $previous);
        $newRow = SheetRow::where('sheet_id', $next->id)->firstOrFail();

        $this->assertSame($project->id, $newRow->project_id);
        $this->assertSame(['150000000'], SheetCell::where('row_id', $newRow->id)->pluck('value')->all());

        $withValues = $builder->create($manager, 1405, 7, $previous, copyAllValues: true);
        $this->assertSame(2, SheetCell::whereIn('row_id', SheetRow::where('sheet_id', $withValues->id)->pluck('id'))->count());
    }
}
