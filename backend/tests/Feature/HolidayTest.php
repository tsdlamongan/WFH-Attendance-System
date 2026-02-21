<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class HolidayTest extends TestCase
{
    use RefreshDatabase, CreatesTeamUsers;

    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createManager([
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->employee = $this->createEmployee([
            'email' => 'employee@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_user_can_view_holidays(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $this->createHoliday([
            'date' => Carbon::parse('2024-12-25'),
            'name' => 'Christmas Day',
        ]);

        $response = $this->getJson('/api/v1/holidays?year=2024', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'date',
                        'name',
                        'description',
                    ],
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_create_holiday(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2024-12-25',
            'name' => 'Christmas Day',
            'description' => 'Company holiday - Christmas celebration',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'date',
                    'name',
                    'description',
                ],
                'message',
            ])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('holidays', [
            'name' => 'Christmas Day',
            'team_id' => $this->manager->team_id,
        ]);

        $holiday = Holiday::where('name', 'Christmas Day')->first();
        $this->assertNotNull($holiday->team_id, 'Holiday must have team_id set when created by manager');
        $this->assertEquals($this->manager->team_id, $holiday->team_id);
        $this->assertEquals('2024-12-25', $holiday->date->format('Y-m-d'));
    }

    public function test_manager_can_update_holiday(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $holiday = $this->createHoliday([
            'date' => Carbon::parse('2024-12-25'),
            'name' => 'Christmas Day',
        ]);

        $response = $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [
            'date' => '2024-12-25',
            'name' => 'Updated Holiday Name',
            'description' => 'Updated description',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('holidays', [
            'id' => $holiday->id,
            'name' => 'Updated Holiday Name',
        ]);
    }

    public function test_manager_can_delete_holiday(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $holiday = $this->createHoliday();

        $response = $this->deleteJson("/api/v1/manager/holidays/{$holiday->id}", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('holidays', [
            'id' => $holiday->id,
        ]);
    }

    public function test_employee_cannot_create_holiday(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2024-12-25',
            'name' => 'Christmas Day',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_update_holiday(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;
        $holiday = $this->createHoliday();

        $response = $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [
            'date' => '2024-12-25',
            'name' => 'Updated Name',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_holiday_prevents_check_in(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create holiday for today
        $this->createHoliday([
            'date' => Carbon::today(),
            'name' => 'Test Holiday',
        ]);

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }
}
