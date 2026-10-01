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
            ->set('projectId', (string) $project->id)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('inviteFor', 'پریسا نادری');

        $user = User::where('mobile', '09358880802')->firstOrFail();
        $this->assertSame(Role::Editor, $user->role);
        $this->assertSame($project->id, $user->project_id);
        $this->assertSame(1, Invitation::where('user_id', $user->id)->count());
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
            ->set('projectId', (string) $project->id)
            ->call('invite')
            ->assertHasErrors('projectId');
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
            ->set('projectId', '')
            ->call('invite')
            ->assertHasErrors('projectId');

        $editor = User::factory()->editor(Project::factory()->create())->create();
        $this->actingAs($editor)->get(route('members'))->assertForbidden();
    }
}
