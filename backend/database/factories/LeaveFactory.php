<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\Leave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leave>
 */
class LeaveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = Carbon::tomorrow();
        $endDate = $startDate->copy()->addDays(2);

        return [
            'user_id' => User::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => fake()->sentence(10),
            'status' => LeaveStatus::PENDING,
        ];
    }
}
