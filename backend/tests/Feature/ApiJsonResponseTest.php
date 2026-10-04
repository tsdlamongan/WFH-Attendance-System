<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_without_accept_header_returns_json_401(): void
    {
        // Plain get() does not send "Accept: application/json", like curl or a misconfigured client.
        $response = $this->get('/api/v1/team/settings');

        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unknown_api_route_without_accept_header_returns_json_404(): void
    {
        $response = $this->get('/api/v1/does-not-exist');

        $response->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
