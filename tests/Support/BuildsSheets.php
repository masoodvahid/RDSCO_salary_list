<?php

namespace Tests\Support;

use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetProject;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\SheetBuilder;
use App\Support\NationalCode;

trait BuildsSheets
{
    protected User $manager;

    protected Project $projectA;

    protected Project $projectB;

    protected Sheet $sheet;

    protected SheetColumn $openColumn;

    protected SheetColumn $lockedColumn;

    protected SheetColumn $textColumn;

    protected SheetRow $rowA;

    protected SheetRow $rowB;

    private int $nationalSeed = 1000;

    protected function buildSheet(): void
    {
        $this->manager = User::factory()->manager()->create();
        $this->projectA = Project::factory()->create(['name' => 'دماوند']);
        $this->projectB = Project::factory()->create(['name' => 'سپهر']);

        $this->sheet = app(SheetBuilder::class)->create($this->manager, 1405, 6, deadline: now()->addDays(5));
        SheetColumn::where('sheet_id', $this->sheet->id)->delete();

        $this->openColumn = SheetColumn::create(['sheet_id' => $this->sheet->id, 'title' => 'اضافه‌کار', 'type' => 'number', 'is_locked' => false, 'position' => 1]);
        $this->lockedColumn = SheetColumn::create(['sheet_id' => $this->sheet->id, 'title' => 'حقوق پایه', 'type' => 'number', 'is_locked' => true, 'position' => 2]);
        $this->textColumn = SheetColumn::create(['sheet_id' => $this->sheet->id, 'title' => 'توضیحات', 'type' => 'text', 'is_locked' => false, 'position' => 3]);

        $this->rowA = $this->makeRow($this->projectA);
        $this->rowB = $this->makeRow($this->projectB);
    }

    protected function makeRow(?Project $project): SheetRow
    {
        return SheetRow::create([
            'sheet_id' => $this->sheet->id,
            'project_id' => $project?->id,
            'first_name' => 'نفر',
            'last_name' => (string) $this->nationalSeed,
            'national_code' => $this->validNationalCode(),
            'position' => $this->nationalSeed,
        ]);
    }

    protected function validNationalCode(): string
    {
        $this->nationalSeed++;

        return NationalCode::fromNineDigits(str_pad((string) (123450000 + $this->nationalSeed), 9, '0', STR_PAD_LEFT));
    }

    protected function sheetProject(Project $project): SheetProject
    {
        return SheetProject::where('sheet_id', $this->sheet->id)->where('project_id', $project->id)->firstOrFail();
    }
}
