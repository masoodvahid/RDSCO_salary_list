<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'پروژه '.fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ];
    }
}
