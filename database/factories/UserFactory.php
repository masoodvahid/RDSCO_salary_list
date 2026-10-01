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
            'project_id' => null,
            'is_active' => true,
        ];
    }

    public function manager(): static
    {
        return $this->state(['role' => Role::Manager, 'project_id' => null]);
    }

    public function editor(Project $project): static
    {
        return $this->state(['role' => Role::Editor, 'project_id' => $project->id]);
    }

    public function approver(?Project $project = null): static
    {
        return $this->state(['role' => Role::Approver, 'project_id' => $project?->id]);
    }

    public function viewer(?Project $project = null): static
    {
        return $this->state(['role' => Role::Viewer, 'project_id' => $project?->id]);
    }
}
