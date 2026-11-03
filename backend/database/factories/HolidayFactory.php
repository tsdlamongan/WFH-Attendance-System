<?php

namespace Database\Factories;

use App\Models\Holiday;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
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
            'date' => Carbon::now()->addMonths(rand(1, 6))->format('Y-m-d'),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
