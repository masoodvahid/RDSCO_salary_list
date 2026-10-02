<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\ChangeLog;
use App\Models\Sheet;
use App\Models\SheetRow;
use App\Models\User;
use App\Models\UserLog;
use App\Services\ApprovalService;
use App\Services\SheetBuilder;
use App\Services\SheetEditor;
use App\Services\SheetLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

/** Deleting a monthly list and moving it to another month. */
class SheetLifecycleTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    private SheetLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet(); // شهریور ۱۴۰۵ with two people
        $this->lifecycle = app(SheetLifecycle::class);
    }

    private function approveProjectA(): void
    {
        $approver = User::factory()->approver($this->projectA)->create();
        $approvals = app(ApprovalService::class);
        $challenge = $approvals->start($approver, $this->sheetProject($this->projectA));
        $approvals->confirm($approver, $challenge, $this->sms->lastCodeFor($approver->mobile));
    }

    public function test_an_empty_list_is_deleted_and_the_deletion_is_logged(): void
    {
        $empty = app(SheetBuilder::class)->create($this->manager, 1405, 8);

        Livewire::actingAs($this->manager)
            ->test(Dashboard::class)
            ->set('sheetId', $empty->id)
            ->call('openDelete')
            ->assertSee('این لیست هیچ نفری ندارد')
            ->call('confirmDelete')
            ->assertHasNoErrors()
            ->assertSet('modal', null)
            ->assertSee('لیست حقوق آبان ۱۴۰۵ حذف شد.');

        $this->assertNull(Sheet::find($empty->id));
        $log = UserLog::where('action', 'sheet.deleted')->sole();
        $this->assertSame('آبان ۱۴۰۵', $log->meta['title']);
        $this->assertSame($this->manager->id, $log->actor_id);
    }

    public function test_a_list_with_people_but_no_approval_asks_to_remove_the_people_first(): void
    {
        $this->assertStringContainsString('۲ نفر دارد. اول نفرات را از لیست حذف کنید', $this->lifecycle->deleteBlocker($this->sheet));

        Livewire::actingAs($this->manager)
            ->test(Dashboard::class)
            ->set('sheetId', $this->sheet->id)
            ->call('openDelete')
            ->assertSee('اول نفرات را از لیست حذف کنید')
            ->assertSee('data-open-list', false)
            ->assertDontSee('wire:click="confirmDelete"', false);

        try {
            $this->lifecycle->delete($this->manager, $this->sheet);
            $this->fail('A list with people is not deleted.');
        } catch (ValidationException) {
        }
        $this->assertNotNull($this->sheet->fresh());

        // Once the people are removed, the list can go.
        app(SheetEditor::class)->deleteRows($this->manager, $this->sheet, SheetRow::where('sheet_id', $this->sheet->id)->pluck('id')->all());
        $this->assertNull($this->lifecycle->deleteBlocker($this->sheet));
        $this->lifecycle->delete($this->manager, $this->sheet);
        $this->assertNull($this->sheet->fresh());
    }

    public function test_an_approved_list_with_people_cannot_be_deleted(): void
    {
        $this->approveProjectA();

        $this->assertStringContainsString('تایید شده است؛ امکان حذف آن وجود ندارد', $this->lifecycle->deleteBlocker($this->sheet));
        Livewire::actingAs($this->manager)
            ->test(Dashboard::class)
            ->set('sheetId', $this->sheet->id)
            ->call('openDelete')
            ->assertSee('امکان حذف آن وجود ندارد')
            ->assertDontSee('data-open-list', false);

        $this->expectException(ValidationException::class);
        $this->lifecycle->delete($this->manager, $this->sheet);
    }

    public function test_a_list_moves_to_a_free_month_with_its_default_deadline(): void
    {
        $list = app(SheetBuilder::class)->create($this->manager, 1405, 8); // آبان, default deadline
        $row = $this->makeRow($this->projectA);
        $row->update(['sheet_id' => $list->id]);

        Livewire::actingAs($this->manager)
            ->test(Dashboard::class)
            ->set('sheetId', $list->id)
            ->call('openMove')
            ->assertSet('moveMonth', 8)
            ->set('moveMonth', 5)
            ->call('saveMove')
            ->assertHasNoErrors()
            ->assertSee('ماه لیست به مرداد ۱۴۰۵ تغییر کرد.')
            ->assertSee('مهلت تکمیل هم به');

        $list->refresh();
        $this->assertSame([1405, 5], [$list->jalali_year, $list->jalali_month]);
        $this->assertSame(app(SheetBuilder::class)->defaultDeadline(1405, 5)->timestamp, $list->deadline_at->timestamp);
        $this->assertSame($list->id, SheetRow::find($row->id)->sheet_id);
        $this->assertSame(['آبان ۱۴۰۵', 'مرداد ۱۴۰۵'], ChangeLog::where('action', 'sheet.month')->get(['old_value', 'new_value'])->map(fn ($l) => [$l->old_value, $l->new_value])->first());
    }

    public function test_a_hand_set_deadline_is_kept_when_moving(): void
    {
        $deadline = $this->sheet->deadline_at->timestamp; // set by hand in the fixture

        $this->assertFalse($this->lifecycle->moveTo($this->manager, $this->sheet, 1405, 4));
        $this->assertSame($deadline, $this->sheet->fresh()->deadline_at->timestamp);
    }

    public function test_moving_onto_an_existing_month_needs_it_deleted_and_never_onto_an_approved_one(): void
    {
        $other = app(SheetBuilder::class)->create($this->manager, 1405, 8);

        $this->assertSame('لیست حقوق شهریور ۱۴۰۵ از قبل وجود دارد. اگر به آن نیاز ندارید، اول آن را حذف کنید و دوباره ماه را تغییر دهید.', $this->lifecycle->moveBlocker($other, 1405, 6));

        Livewire::actingAs($this->manager)
            ->test(Dashboard::class)
            ->set('sheetId', $other->id)
            ->call('openMove')
            ->set('moveMonth', 6)
            ->assertSee('از قبل وجود دارد')
            ->call('saveMove')
            ->assertHasErrors('month');
        $this->assertSame(8, $other->fresh()->jalali_month);

        $this->approveProjectA();
        $this->assertSame('لیست حقوق شهریور ۱۴۰۵ تاییدیه دارد و امکان انتقال به آن وجود ندارد.', $this->lifecycle->moveBlocker($other, 1405, 6));
    }

    public function test_an_approved_list_keeps_its_month(): void
    {
        $this->approveProjectA();

        $this->assertStringContainsString('تایید شده است؛ برای تغییر ماه', $this->lifecycle->moveBlocker($this->sheet, 1405, 4));
        $this->assertSame('این لیست همین حالا برای همین ماه است.', $this->lifecycle->moveBlocker($this->sheet, 1405, 6));
        $this->assertSame('ماه انتخاب‌شده معتبر نیست.', $this->lifecycle->moveBlocker($this->sheet, 1405, 13));
    }

    public function test_only_managers_delete_or_move_lists(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        Livewire::actingAs($editor)->test(Dashboard::class)->call('openDelete')->assertForbidden();
        Livewire::actingAs($editor)->test(Dashboard::class)->call('openMove')->assertForbidden();

        $this->expectException(AuthorizationException::class);
        $this->lifecycle->moveTo($editor, $this->sheet, 1405, 4);
    }
}
