<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'mobile' => '09'.fake()->unique()->numerify('#########'),
            'role' => Role::Viewer,
            'is_active' => true,
        ];
    }

    public function manager(): static
    {
        return $this->state(['role' => Role::Manager]);
    }

    /** At least one project: an editor always works within projects. */
    public function editor(Project $project, Project ...$more): static
    {
        return $this->state(['role' => Role::Editor])->inProjects($project, ...$more);
    }

    /** No project = finance (final stage) approver. */
    public function approver(Project ...$projects): static
    {
        return $this->state(['role' => Role::Approver])->inProjects(...$projects);
    }

    /** No project = sees every project. */
    public function viewer(Project ...$projects): static
    {
        return $this->state(['role' => Role::Viewer])->inProjects(...$projects);
    }

    public function inProjects(Project ...$projects): static
    {
        if ($projects === []) {
            return $this;
        }

        return $this->afterCreating(fn (User $user) => $user->syncProjects(array_map(fn (Project $p) => $p->id, $projects)));
    }
}
