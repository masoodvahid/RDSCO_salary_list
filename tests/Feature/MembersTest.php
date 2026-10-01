<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Members;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_invites_a_member_with_role_and_scope(): void
    {
        $manager = User::factory()->manager()->create();
        $project = Project::factory()->create();

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('name', 'پریسا نادری')
            ->set('mobile', '09358880802')
            ->set('role', 'editor')
            ->set('projectIds', [(string) $project->id])
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('inviteFor', 'پریسا نادری');

        $user = User::where('mobile', '09358880802')->firstOrFail();
        $this->assertSame(Role::Editor, $user->role);
        $this->assertSame([$project->id], $user->projectIds());
        $this->assertSame(1, Invitation::where('user_id', $user->id)->count());
    }

    public function test_job_title_is_saved_on_invite_and_edit_and_is_searchable(): void
    {
        $manager = User::factory()->manager()->create();
        $project = Project::factory()->create();

        $component = Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('name', 'کاوه مرادی')
            ->set('jobTitle', '  مدیر   داخلی پروژه ')
            ->set('mobile', '09121112233')
            ->set('role', 'approver')
            ->set('projectIds', [(string) $project->id])
            ->call('invite')
            ->assertHasNoErrors();

        $user = User::where('mobile', '09121112233')->firstOrFail();
        $this->assertSame('مدیر داخلی پروژه', $user->job_title);
        $this->assertSame('کاوه مرادی (مدیر داخلی پروژه)', $user->nameWithTitle());

        $component->set('search', 'داخلی')->assertSee('کاوه مرادی')
            ->set('search', '')
            ->call('startEdit', $user->id)
            ->assertSet('editJobTitle', 'مدیر داخلی پروژه')
            ->set('editJobTitle', 'مسئول حسابداری')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('مسئول حسابداری', $user->fresh()->job_title);

        $component->call('startEdit', $user->id)->set('editJobTitle', '')->call('saveEdit');
        $this->assertNull($user->fresh()->job_title);
        $this->assertSame('کاوه مرادی', $user->fresh()->nameWithTitle());
    }

    public function test_manager_edits_a_member_name(): void
    {
        $manager = User::factory()->manager()->create();
        $member = User::factory()->viewer()->create(['name' => 'نام قدیمی']);

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->call('startEdit', $member->id)
            ->assertSet('editName', 'نام قدیمی')
            ->set('editName', '  نام   جدید ')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('نام جدید', $member->fresh()->name);

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->call('startEdit', $member->id)
            ->set('editName', '  ')
            ->call('saveEdit')
            ->assertHasErrors('editName');

        $this->assertSame('نام جدید', $member->fresh()->name);
    }

    public function test_a_project_has_at_most_one_active_approver(): void
    {
        $manager = User::factory()->manager()->create();
        $project = Project::factory()->create();
        User::factory()->approver($project)->create();

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('name', 'دومی')
            ->set('mobile', '09121234567')
            ->set('role', 'approver')
            ->set('projectIds', [(string) $project->id])
            ->call('invite')
            ->assertHasErrors('projectIds');
    }

    public function test_database_enforces_one_active_approver_per_project(): void
    {
        $project = Project::factory()->create();
        User::factory()->approver($project)->create();
        User::factory()->approver($project)->create(['is_active' => false]);
        User::factory()->approver()->create();
        User::factory()->approver()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->approver($project)->create();
    }

    public function test_editor_needs_a_project_and_non_managers_cannot_open_the_page(): void
    {
        $manager = User::factory()->manager()->create();

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('name', 'بی‌پروژه')
            ->set('mobile', '09121234560')
            ->set('role', 'editor')
            ->set('projectIds', [])
            ->call('invite')
            ->assertHasErrors('projectIds');

        // "Every project" is not an option for an editor either.
        Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('name', 'بی‌پروژه')
            ->set('mobile', '09121234560')
            ->set('role', 'editor')
            ->set('scope', 'all')
            ->call('invite')
            ->assertHasErrors('projectIds');
        $this->assertFalse(User::where('mobile', '09121234560')->exists());

        $editor = User::factory()->editor(Project::factory()->create())->create();
        $this->actingAs($editor)->get(route('members'))->assertForbidden();
    }

    public function test_a_member_can_be_given_several_projects_and_edited_later(): void
    {
        $manager = User::factory()->manager()->create();
        [$a, $b, $c] = Project::factory()->count(3)->create()->all();

        $component = Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('name', 'سمیرا امینی')
            ->set('mobile', '09121230001')
            ->set('role', 'editor')
            ->set('projectIds', [(string) $a->id, (string) $b->id])
            ->call('invite')
            ->assertHasNoErrors();

        $user = User::where('mobile', '09121230001')->firstOrFail();
        $this->assertSame([$a->id, $b->id], $user->projectIds());
        $this->assertFalse($user->hasAllProjects());

        $component->call('startEdit', $user->id)
            ->assertSet('editScope', 'some')
            ->assertSet('editProjectIds', [(string) $a->id, (string) $b->id])
            ->set('editProjectIds', [(string) $b->id, (string) $c->id])
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $this->assertSame([$b->id, $c->id], $user->fresh()->projectIds());

        // Viewer on every project: no project rows at all.
        $component->call('startEdit', $user->id)
            ->set('editRole', 'viewer')
            ->set('editScope', 'all')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame(Role::Viewer, $fresh->role);
        $this->assertSame([], $fresh->projectIds());
        $this->assertTrue($fresh->hasAllProjects());
        $this->assertSame('همه پروژه‌ها', $fresh->scopeLabel());
    }

    public function test_switching_to_editor_forces_specific_projects(): void
    {
        $manager = User::factory()->manager()->create();

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->set('role', 'viewer')
            ->set('scope', 'all')
            ->set('role', 'editor')
            ->assertSet('scope', 'some');
    }

    public function test_an_approver_of_several_projects_conflicts_name_the_taken_projects(): void
    {
        $manager = User::factory()->manager()->create();
        $a = Project::factory()->create(['name' => 'آلفا']);
        $b = Project::factory()->create(['name' => 'بتا']);
        User::factory()->approver($b)->create(['name' => 'تاییدکننده بتا']);
        $member = User::factory()->approver($a)->create();

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->call('startEdit', $member->id)
            ->set('editProjectIds', [(string) $a->id, (string) $b->id])
            ->call('saveEdit')
            ->assertHasErrors('editProjectIds')
            ->assertSee('پروژه بتا (تاییدکننده بتا)');

        $this->assertSame([$a->id], $member->fresh()->projectIds());
    }

    public function test_an_approver_can_hold_the_approver_slot_of_several_projects(): void
    {
        [$a, $b] = Project::factory()->count(2)->create()->all();
        $approver = User::factory()->approver($a, $b)->create();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $approver->projects()->pluck('project_user.approver_key')->map(fn ($k) => (int) $k)->all());

        // Deactivating frees both slots; another approver can take one of them.
        $approver->update(['is_active' => false]);
        $this->assertSame([null, null], $approver->projects()->pluck('project_user.approver_key')->all());
        User::factory()->approver($a)->create();

        // Reactivating the first one now clashes on project A.
        $manager = User::factory()->manager()->create();
        Livewire::actingAs($manager)
            ->test(Members::class)
            ->call('toggleActive', $approver->id)
            ->assertHasErrors('members');
        $this->assertFalse($approver->fresh()->is_active);
    }

    public function test_changing_role_away_from_approver_frees_the_project(): void
    {
        $manager = User::factory()->manager()->create();
        $project = Project::factory()->create();
        $first = User::factory()->approver($project)->create();

        Livewire::actingAs($manager)
            ->test(Members::class)
            ->call('startEdit', $first->id)
            ->set('editRole', 'viewer')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertNull($first->projects()->first()->pivot->approver_key);
        User::factory()->approver($project)->create();
        $this->assertSame(1, $project->users()->wherePivotNotNull('approver_key')->count());
    }
}
