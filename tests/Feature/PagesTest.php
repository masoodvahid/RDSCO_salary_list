<?php

namespace Tests\Feature;

use App\Livewire\Sheets\Grid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSheets;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use BuildsSheets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSheet();
        $this->rowA->update(['first_name' => 'آرش', 'last_name' => 'دماوندی']);
        $this->rowB->update(['first_name' => 'بردیا', 'last_name' => 'سپهری']);
    }

    public function test_manager_pages_render(): void
    {
        $this->actingAs($this->manager);

        $this->get(route('dashboard'))->assertOk()->assertSee('لیست حقوق شهریور ۱۴۰۵')->assertSee('سامانه مدیریت لیست حقوق')->assertDontSee('شیت')->assertDontSee('توکا')
            ->assertSee('js/busy.js', false)->assertSee('js/picker.js', false);
        $this->get(route('sheets.show', $this->sheet))->assertOk()->assertSee('آرش')->assertSee('بردیا')->assertSee('ستون جدید')
            ->assertSee('data-col-grip', false)->assertSee('data-select-row', false)->assertDontSee('شیت');
        $this->get(route('sheets.create'))->assertOk()->assertSee('لیست حقوق ماه جدید')->assertDontSee('شیت');
    }

    public function test_manager_can_open_create_members_and_projects(): void
    {
        $this->actingAs($this->manager);

        $this->get(route('members'))->assertOk();
        $this->get(route('projects'))->assertOk();
    }

    public function test_editor_sees_only_their_project_and_no_manager_tools(): void
    {
        $editor = User::factory()->editor($this->projectA)->create();

        $this->actingAs($editor)
            ->get(route('sheets.show', $this->sheet))
            ->assertOk()
            ->assertSee('آرش')
            ->assertDontSee('بردیا')
            ->assertDontSee('ستون جدید')
            ->assertDontSee('data-col-grip', false)
            ->assertDontSee('data-select-row', false);

        $this->actingAs($editor)->get(route('sheets.show', ['sheet' => $this->sheet, 'project' => $this->projectB->id]))
            ->assertOk()
            ->assertDontSee('بردیا');

        $this->get(route('projects'))->assertForbidden();
    }

    public function test_grid_actions_for_managers(): void
    {
        $grid = Livewire::actingAs($this->manager)
            ->test(Grid::class, ['sheet' => $this->sheet])
            ->call('openColumn')
            ->set('columnTitle', 'پاداش')
            ->set('columnType', 'number')
            ->call('saveColumn')
            ->assertHasNoErrors();
        // After the first render the table travels as the "table" island.
        $this->assertStringContainsString('پاداش', $this->gridTable($grid));

        $grid->call('filterProject', $this->projectA->id);
        $this->assertStringContainsString('آرش', $this->gridTable($grid));
        $this->assertStringNotContainsString('بردیا', $this->gridTable($grid));
    }

    public function test_version_in_the_footer_and_list_actions_as_icon_buttons(): void
    {
        config(['tuka.version' => '1.5.0']);
        $this->actingAs($this->manager);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('<footer', false)
            ->assertSeeInOrder(['نسخه‌ی', '1.5.0'])
            ->assertSee('href="'.route('system.update').'"', false)
            ->assertSee('aria-label="تغییر ماه لیست حقوق شهریور ۱۴۰۵"', false)
            ->assertSee('aria-label="حذف لیست حقوق شهریور ۱۴۰۵"', false);
        // The grid fills the screen: the version sits in its status bar instead of a page footer.
        $this->get(route('sheets.show', $this->sheet))->assertOk()->assertDontSee('<footer', false)->assertSee('1.5.0');

        config(['tuka.version' => 'dev']);
        $editor = User::factory()->editor($this->projectA)->create();
        $this->actingAs($editor)->get(route('dashboard'))->assertOk()
            ->assertSee('نسخه‌ی توسعه')
            ->assertDontSee('حذف لیست حقوق');
    }

    public function test_export_and_print(): void
    {
        $this->actingAs($this->manager);

        $response = $this->get(route('sheets.export', $this->sheet));
        $response->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $this->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectA->id]))
            ->assertOk()
            ->assertSee('آرش')
            ->assertDontSee('بردیا');
    }

    public function test_viewer_cannot_export_other_projects(): void
    {
        $viewer = User::factory()->viewer($this->projectA)->create();

        $this->actingAs($viewer)->get(route('sheets.print', ['sheet' => $this->sheet, 'project' => $this->projectB->id]))->assertForbidden();
    }
}
