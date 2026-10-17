<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = Carbon::now()->startOfDay()->addHours(9); // 9 AM
        $checkOut = $checkIn->copy()->addHours(8); // 5 PM

        return [
            'user_id' => User::factory(),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'date' => $checkIn->toDateString(),
            'total_hours' => 8.0,
        ];
    }
}
