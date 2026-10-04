<?php

use App\Enums\UserRole;
use App\Models\User;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
|--------------------------------------------------------------------------
| Route id constraints
|--------------------------------------------------------------------------
|
| Controllers receive {id} / {userId} as `int`. A non-numeric (or absurdly long) id used to hit the
| controller and blow up with a TypeError (HTTP 500); it must be a plain 404 from the router.
|
*/

dataset('routes with an id parameter', [
    'PUT manager user' => ['PUT', '/api/v1/manager/users/{id}'],
    'PATCH toggle disabled' => ['PATCH', '/api/v1/manager/users/{id}/toggle-disabled'],
    'DELETE manager user' => ['DELETE', '/api/v1/manager/users/{id}'],
    'PUT manager attendance' => ['PUT', '/api/v1/manager/attendances/{id}'],
    'DELETE manager attendance' => ['DELETE', '/api/v1/manager/attendances/{id}'],
    'PUT manager task' => ['PUT', '/api/v1/manager/tasks/{id}'],
    'PUT holiday' => ['PUT', '/api/v1/manager/holidays/{id}'],
    'DELETE holiday' => ['DELETE', '/api/v1/manager/holidays/{id}'],
    'PUT manager leave' => ['PUT', '/api/v1/manager/leaves/{id}'],
    'PUT approve leave' => ['PUT', '/api/v1/manager/leaves/{id}/approve'],
    'PUT reject leave' => ['PUT', '/api/v1/manager/leaves/{id}/reject'],
    'PUT leave quota' => ['PUT', '/api/v1/manager/leave-quotas/{userId}'],
    'GET employee report' => ['GET', '/api/v1/manager/reports/employee/{userId}'],
    'GET team' => ['GET', '/api/v1/super-admin/teams/{id}'],
    'PUT team' => ['PUT', '/api/v1/super-admin/teams/{id}'],
    'DELETE team' => ['DELETE', '/api/v1/super-admin/teams/{id}'],
    'POST impersonate' => ['POST', '/api/v1/super-admin/impersonate/{userId}'],
]);

it('answers a non-numeric or out-of-range id with a plain 404, never a 500', function (string $method, string $uri) {
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'team_id' => null]);
    $headers = ['Authorization' => 'Bearer '.$superAdmin->createToken('auth-token')->plainTextToken];

    foreach (['abc', '1abc', '-1', '1.5', '99999999999999999999'] as $badId) {
        $path = str_replace(['{id}', '{userId}'], $badId, $uri);

        $this->json($method, $path, [], $headers)->assertNotFound();
    }
})->with('routes with an id parameter');

it('still routes a numeric id to the controller', function () {
    $manager = $this->createManager();
    $headers = ['Authorization' => 'Bearer '.$manager->createToken('auth-token')->plainTextToken];

    // Reaches the controller (which answers its own JSON 404 for an unknown holiday), not the router's 404.
    $this->putJson('/api/v1/manager/holidays/999999', ['date' => '2030-01-01', 'name' => 'x'], $headers)
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Holiday not found');
});
