<?php

namespace Database\Factories;

use App\Enums\OvertimeStatus;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Overtime>
 */
class OvertimeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => Carbon::today(),
            'planned_minutes' => 60,
            'reason' => fake()->sentence(8),
            'status' => OvertimeStatus::PENDING,
            'source' => Overtime::SOURCE_EMPLOYEE,
        ];
    }

    public function approved(?User $approver = null, ?int $approvedMinutes = null): static
    {
        return $this->state(fn () => [
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $approver?->id,
            'approved_at' => Carbon::now(),
            'approved_minutes' => $approvedMinutes,
        ]);
    }

    public function rejected(?User $approver = null): static
    {
        return $this->state(fn () => [
            'status' => OvertimeStatus::REJECTED,
            'approved_by' => $approver?->id,
            'approved_at' => Carbon::now(),
            'notes' => 'Ditolak untuk pengujian',
        ]);
    }
}
