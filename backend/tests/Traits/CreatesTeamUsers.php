<?php

namespace Tests\Traits;

use App\Models\Team;
use App\Models\User;

trait CreatesTeamUsers
{
    protected ?Team $team = null;

    /**
     * Create a team for testing.
     */
    protected function createTeam(array $attributes = []): Team
    {
        return Team::factory()->create($attributes);
    }

    /**
     * Create a user with team.
     */
    protected function createUser(array $attributes = []): User
    {
        if ($this->team === null) {
            $this->team = $this->createTeam();
        }

        return User::factory()->create(array_merge([
            'team_id' => $this->team->id,
        ], $attributes));
    }

    /**
     * Create a manager with team.
     */
    protected function createManager(array $attributes = []): User
    {
        return $this->createUser(array_merge([
            'role' => \App\Enums\UserRole::MANAGER,
        ], $attributes));
    }

    /**
     * Create an employee with team.
     */
    protected function createEmployee(array $attributes = []): User
    {
        return $this->createUser(array_merge([
            'role' => \App\Enums\UserRole::EMPLOYEE,
        ], $attributes));
    }

    /**
     * Create a disabled employee with team.
     */
    protected function createDisabledEmployee(array $attributes = []): User
    {
        return $this->createEmployee(array_merge([
            'is_disabled' => true,
        ], $attributes));
    }

    /**
     * Create a holiday for the team.
     */
    protected function createHoliday(array $attributes = []): \App\Models\Holiday
    {
        if ($this->team === null) {
            $this->team = $this->createTeam();
        }

        return \App\Models\Holiday::factory()->create(array_merge([
            'team_id' => $this->team->id,
        ], $attributes));
    }

    /**
     * Create an activity log for the team.
     */
    protected function createActivityLog(array $attributes = []): \App\Models\ActivityLog
    {
        if ($this->team === null) {
            $this->team = $this->createTeam();
        }

        return \App\Models\ActivityLog::factory()->create(array_merge([
            'team_id' => $this->team->id,
        ], $attributes));
    }

    /**
     * Create a leave request.
     */
    protected function createLeave(array $attributes = []): \App\Models\Leave
    {
        // If user_id is not provided, create a user in the team
        if (!isset($attributes['user_id'])) {
            $user = $this->createEmployee();
            $attributes['user_id'] = $user->id;
        }

        return \App\Models\Leave::factory()->create($attributes);
    }
}
