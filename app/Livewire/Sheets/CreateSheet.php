<?php

namespace App\Livewire\Sheets;

use App\Models\Sheet;
use App\Services\SheetAccess;
use App\Services\SheetBuilder;
use App\Support\Jalali;
use Livewire\Component;

class CreateSheet extends Component
{
    /** @var int|string */
    public $year = 0;

    /** @var int|string */
    public $month = 1;

    /** @var int|string|null */
    public $copyFromId = null;

    public bool $copyAllValues = false;

    public string $deadline = '';

    public function mount(SheetAccess $access, SheetBuilder $builder): void
    {
        abort_unless($access->canManage(auth()->user()), 403);

        $latest = Sheet::orderByDesc('jalali_year')->orderByDesc('jalali_month')->first();
        if ($latest) {
            [$this->year, $this->month] = Jalali::nextMonth($latest->jalali_year, $latest->jalali_month);
            $this->copyFromId = $latest->id;
        } else {
            // Payroll is usually prepared for the month that just ended.
            [$jy, $jm] = Jalali::fromCarbon(now());
            [$this->year, $this->month] = Jalali::previousMonth($jy, $jm);
        }

        $this->deadline = Jalali::formatShort($builder->defaultDeadline($this->year, $this->month));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['year', 'month'], true) && Jalali::isValid((int) $this->year, (int) $this->month, 1)) {
            $this->deadline = Jalali::formatShort(app(SheetBuilder::class)->defaultDeadline((int) $this->year, (int) $this->month));
        }
    }

    public function create(SheetBuilder $builder)
    {
        abort_unless(app(SheetAccess::class)->canManage(auth()->user()), 403);

        $date = Jalali::parse($this->deadline);
        if (! $date) {
            $this->addError('deadline', 'مهلت را به شکل ۱۴۰۵/۰۷/۱۴ وارد کنید.');

            return null;
        }

        $sheet = $builder->create(
            auth()->user(),
            (int) $this->year,
            (int) $this->month,
            $this->copyFromId ? Sheet::find($this->copyFromId) : null,
            $this->copyAllValues,
            Jalali::toCarbon(...$date)->endOfDay(),
        );

        return $this->redirectRoute('sheets.show', ['sheet' => $sheet->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.sheets.create', [
            'sheets' => Sheet::orderByDesc('jalali_year')->orderByDesc('jalali_month')->get(),
            'months' => Jalali::monthNames(),
        ])->title('لیست حقوق ماه جدید');
    }
}
