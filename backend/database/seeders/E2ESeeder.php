<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class E2ESeeder extends Seeder
{
    /**
     * Seed deterministic users + teams for Playwright E2E tests.
     */
    public function run(): void
    {
        $password = Hash::make('Password1!');

        User::updateOrCreate(
            ['email' => 'superadmin@e2e.test'],
            [
                'team_id' => null,
                'name' => 'E2E Super Admin',
                'password' => $password,
                'role' => UserRole::SUPER_ADMIN,
                'leave_quota_days' => 0,
                'is_disabled' => false,
            ]
        );

        $teamAlpha = Team::updateOrCreate(
            ['slug' => 'team-alpha-e2e'],
            [
                'name' => 'Team Alpha E2E',
                'description' => 'Primary team for E2E tests',
                'required_work_hours' => 7.0,
                'default_leave_quota_days' => 12,
                'max_leave_days_per_month' => 5,
                'is_active' => true,
            ]
        );

        $teamBeta = Team::updateOrCreate(
            ['slug' => 'team-beta-e2e'],
            [
                'name' => 'Team Beta E2E',
                'description' => 'Secondary team for isolation tests',
                'required_work_hours' => 8.0,
                'default_leave_quota_days' => 10,
                'max_leave_days_per_month' => 4,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'manager.alpha@e2e.test'],
            [
                'team_id' => $teamAlpha->id,
                'name' => 'Manager Alpha',
                'password' => $password,
                'role' => UserRole::MANAGER,
                'leave_quota_days' => $teamAlpha->default_leave_quota_days,
                'is_disabled' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'manager.beta@e2e.test'],
            [
                'team_id' => $teamBeta->id,
                'name' => 'Manager Beta',
                'password' => $password,
                'role' => UserRole::MANAGER,
                'leave_quota_days' => $teamBeta->default_leave_quota_days,
                'is_disabled' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'employee.alpha1@e2e.test'],
            [
                'team_id' => $teamAlpha->id,
                'name' => 'Employee Alpha One',
                'password' => $password,
                'role' => UserRole::EMPLOYEE,
                'leave_quota_days' => $teamAlpha->default_leave_quota_days,
                'is_disabled' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'employee.alpha2@e2e.test'],
            [
                'team_id' => $teamAlpha->id,
                'name' => 'Employee Alpha Two',
                'password' => $password,
                'role' => UserRole::EMPLOYEE,
                'leave_quota_days' => $teamAlpha->default_leave_quota_days,
                'is_disabled' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'employee.beta1@e2e.test'],
            [
                'team_id' => $teamBeta->id,
                'name' => 'Employee Beta One',
                'password' => $password,
                'role' => UserRole::EMPLOYEE,
                'leave_quota_days' => $teamBeta->default_leave_quota_days,
                'is_disabled' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'disabled.alpha@e2e.test'],
            [
                'team_id' => $teamAlpha->id,
                'name' => 'Disabled User',
                'password' => $password,
                'role' => UserRole::EMPLOYEE,
                'leave_quota_days' => $teamAlpha->default_leave_quota_days,
                'is_disabled' => true,
            ]
        );
    }
}
