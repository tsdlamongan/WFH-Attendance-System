<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'pic' => null,
            'progress' => 0,
            'status' => ProjectStatus::ON_PROGRESS,
            'note' => null,
            'is_active' => true,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::COMPLETED,
            'progress' => 100,
        ]);
    }

    public function hold(): static
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::HOLD,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
