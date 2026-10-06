<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Sheet;
use App\Models\SheetCell;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use App\Services\SheetBuilder;
use App\Support\Jalali;
use App\Support\NationalCode;
use Illuminate\Database\Seeder;

/**
 * Local demo: one user per role and the previous month's sheet with sample personnel.
 * Log in with any of the mobiles below; without KAVENEGAR_API_KEY the code is in storage/logs.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $projects = collect(['دماوند', 'سپهر', 'آتیه', 'البرز'])
            ->mapWithKeys(fn ($name) => [$name => Project::firstOrCreate(['name' => $name], ['is_active' => true])]);

        $manager = User::updateOrCreate(['mobile' => '09120000000'], ['name' => 'مدیر منابع انسانی (نمونه)', 'job_title' => 'مدیر منابع انسانی', 'role' => Role::Manager, 'is_active' => true]);
        User::updateOrCreate(['mobile' => '09120000001'], ['name' => 'ویرایشگر دماوند و سپهر (نمونه)', 'job_title' => 'مسئول اداری پروژه', 'role' => Role::Editor, 'is_active' => true])
            ->syncProjects([$projects['دماوند']->id, $projects['سپهر']->id]);
        User::updateOrCreate(['mobile' => '09120000002'], ['name' => 'مدیر پروژه دماوند (نمونه)', 'job_title' => 'مدیر داخلی پروژه', 'role' => Role::Approver, 'is_active' => true])
            ->syncProjects([$projects['دماوند']->id]);
        User::updateOrCreate(['mobile' => '09120000003'], ['name' => 'مدیر مالی (نمونه)', 'job_title' => 'مدیر مالی', 'role' => Role::Finance, 'is_active' => true])
            ->syncProjects([]);
        User::updateOrCreate(['mobile' => '09120000004'], ['name' => 'مدیرعامل (نمونه)', 'job_title' => 'مدیرعامل', 'role' => Role::Approver, 'is_active' => true])
            ->syncProjects([]);

        [$jy, $jm] = Jalali::previousMonth(...array_slice(Jalali::fromCarbon(now()), 0, 2));
        if (Sheet::where('jalali_year', $jy)->where('jalali_month', $jm)->exists()) {
            return;
        }

        $sheet = app(SheetBuilder::class)->create($manager, $jy, $jm);

        // Sample payroll columns (a real sheet gets them from the Excel import or adds them by hand).
        $demoColumns = [
            ['کارکرد (روز)', 'number', false, '0', '31'],
            ['اضافه‌کار (ساعت)', 'number', false, '0', '120'],
            ['حقوق پایه', 'number', true, null, null],
            ['حق مسکن', 'number', true, null, null],
            ['بن خواربار', 'number', true, null, null],
            ['مساعده', 'number', false, null, null],
            ['توضیحات', 'text', false, null, null],
        ];
        foreach ($demoColumns as $i => [$title, $type, $locked, $min, $max]) {
            SheetColumn::create([
                'sheet_id' => $sheet->id, 'title' => $title, 'type' => $type, 'is_locked' => $locked,
                'min_value' => $min, 'max_value' => $max, 'position' => $i + 1,
            ]);
        }
        $columns = SheetColumn::where('sheet_id', $sheet->id)->orderBy('position')->get()->keyBy('title');

        $people = [
            ['محمد', 'کریمی', 'دماوند'], ['زهرا', 'احمدی', 'دماوند'], ['علی', 'رضایی', 'دماوند'], ['فاطمه', 'موسوی', 'دماوند'],
            ['حسین', 'نوری', 'دماوند'], ['سارا', 'حسینی', 'دماوند'], ['رضا', 'جعفری', 'سپهر'], ['مریم', 'صادقی', 'سپهر'],
            ['کامران', 'بهاری', 'سپهر'], ['لیلا', 'کرمانی', 'آتیه'], ['سعید', 'ملکی', 'آتیه'], ['نرگس', 'یزدانی', 'البرز'],
            ['امیر', 'شریفی', null],
        ];

        foreach ($people as $i => [$first, $last, $project]) {
            $row = SheetRow::create([
                'sheet_id' => $sheet->id,
                'project_id' => $project ? $projects[$project]->id : null,
                'first_name' => $first,
                'last_name' => $last,
                'personnel_code' => (string) (10200 + $i * 7),
                'national_code' => NationalCode::fromNineDigits(str_pad((string) (1245876 + $i * 104729), 9, '0', STR_PAD_LEFT)),
                'position' => $i + 1,
            ]);

            $values = [
                'حقوق پایه' => (string) (135_000_000 + ($i % 5) * 7_500_000),
                'حق مسکن' => '9000000',
                'بن خواربار' => '14000000',
                'کارکرد (روز)' => $i % 4 === 0 ? null : '31',
            ];
            foreach ($values as $title => $value) {
                if ($value !== null && $columns->has($title)) {
                    SheetCell::create(['row_id' => $row->id, 'column_id' => $columns[$title]->id, 'value' => $value, 'version' => 1, 'updated_by' => $manager->id]);
                }
            }
        }
    }
}
