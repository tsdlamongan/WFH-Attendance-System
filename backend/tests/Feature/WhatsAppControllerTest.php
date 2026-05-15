<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsAppControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create([
            'name' => 'Test Team',
        ]);

        $this->manager = User::factory()->create([
            'email' => 'manager@test.com',
            'role' => UserRole::MANAGER,
            'team_id' => $this->team->id,
        ]);

        $this->employee = User::factory()->create([
            'email' => 'employee@test.com',
            'role' => UserRole::EMPLOYEE,
            'team_id' => $this->team->id,
        ]);
    }

    #[Test]
    public function manager_can_create_qr_link()
    {
        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/create/wa.link*' => Http::response([
                'status' => 200,
                'data' => [
                    'qrstring' => 'mock-qr-string',
                    'qrimagelink' => 'https://example.com/qr.png',
                    'infolink' => 'https://example.com/info?token=mock-token-123',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/create-link', [
                'api_secret' => 'test-api-secret-123',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'qr_string' => 'mock-qr-string',
                    'qr_image_url' => 'https://example.com/qr.png',
                    'token' => 'mock-token-123',
                ],
            ]);

        // Assert team updated
        $this->team->refresh();
        $this->assertNotNull($this->team->whatsapp_api_secret);
        $this->assertEquals('mock-token-123', $this->team->whatsapp_token);
    }

    #[Test]
    public function manager_can_link_existing_account()
    {
        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.accounts*' => Http::response([
                'status' => 200,
                'data' => [
                    [
                        'unique' => 'test-unique-id-1234567890123456789012345',
                        'phone' => '6281234567890',
                        'name' => 'Test Account',
                        'status' => 'connected',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/link-existing', [
                'api_secret' => 'test-api-secret-123',
                'unique_id' => 'test-unique-id-1234567890123456789012345',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'connected' => true,
                    'account_unique_id' => 'test-unique-id-1234567890123456789012345',
                    'account_phone' => '6281234567890',
                    'account_name' => 'Test Account',
                ],
            ]);

        // Assert team updated
        $this->team->refresh();
        $this->assertTrue($this->team->whatsapp_connected);
        $this->assertEquals('test-unique-id-1234567890123456789012345', $this->team->whatsapp_account_unique_id);
        $this->assertEquals('6281234567890', $this->team->whatsapp_account_phone);

        // Assert activity logged
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::WHATSAPP_CONNECTED->value,
        ]);
    }

    #[Test]
    public function link_existing_fails_if_account_not_found()
    {
        // Mock WhatsApp Gateway API response with no accounts
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.accounts*' => Http::response([
                'status' => 200,
                'data' => [],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/link-existing', [
                'api_secret' => 'test-api-secret-123',
                'unique_id' => 'non-existent-id-1234567890123456789012345',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Account tidak ditemukan di WhatsApp Gateway. Pastikan Unique ID benar.',
            ]);
    }

    #[Test]
    public function link_existing_fails_if_account_not_connected()
    {
        // Mock WhatsApp Gateway API response with disconnected account
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.accounts*' => Http::response([
                'status' => 200,
                'data' => [
                    [
                        'unique' => 'test-unique-id-1234567890123456789012345',
                        'phone' => '6281234567890',
                        'name' => 'Test Account',
                        'status' => 'disconnected',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/link-existing', [
                'api_secret' => 'test-api-secret-123',
                'unique_id' => 'test-unique-id-1234567890123456789012345',
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    #[Test]
    public function manager_can_get_account_info()
    {
        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.info*' => Http::response([
                'status' => 200,
                'data' => [
                    'wid' => '6281234567890:1@s.whatsapp.net',
                    'unique' => 'test-unique-id-1234567890123456789012345',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson('/api/v1/whatsapp/account-info?token=test-token');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'unique_id' => 'test-unique-id-1234567890123456789012345',
                    'phone' => '6281234567890',
                ],
            ]);
    }

    #[Test]
    public function manager_can_check_connection()
    {
        // Setup team with API secret
        $this->team->update([
            'whatsapp_api_secret' => encrypt('test-api-secret'),
        ]);

        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.accounts*' => Http::response([
                'status' => 200,
                'data' => [
                    [
                        'unique' => 'test-unique-id-1234567890123456789012345',
                        'phone' => '6281234567890',
                        'name' => 'Test Account',
                        'status' => 'connected',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson('/api/v1/whatsapp/check-connection');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'unique_id' => 'test-unique-id-1234567890123456789012345',
                ],
            ]);

        // Assert team updated
        $this->team->refresh();
        $this->assertTrue($this->team->whatsapp_connected);
    }

    #[Test]
    public function manager_can_confirm_link()
    {
        // Setup team with token
        $this->team->update([
            'whatsapp_token' => 'test-token',
        ]);

        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.info*' => Http::response([
                'status' => 200,
                'data' => [
                    'wid' => '6281234567890:1@s.whatsapp.net',
                    'unique' => 'test-unique-id-1234567890123456789012345',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/confirm-link', [
                'token' => 'test-token',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'connected' => true,
                ],
            ]);

        // Assert team updated and token cleared
        $this->team->refresh();
        $this->assertTrue($this->team->whatsapp_connected);
        $this->assertNull($this->team->whatsapp_token);
    }

    #[Test]
    public function manager_can_relink_account()
    {
        // Setup team with existing connection
        $this->team->update([
            'whatsapp_api_secret' => encrypt('test-api-secret'),
            'whatsapp_account_unique_id' => 'old-unique-id',
            'whatsapp_connected' => true,
        ]);

        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/create/wa.relink*' => Http::response([
                'status' => 200,
                'data' => [
                    'qrstring' => 'mock-qr-string',
                    'qrimagelink' => 'https://example.com/qr.png',
                    'infolink' => 'https://example.com/info?token=new-token',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/relink');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'qr_string' => 'mock-qr-string',
                    'qr_image_url' => 'https://example.com/qr.png',
                    'token' => 'new-token',
                ],
            ]);

        // Assert team marked as disconnected temporarily
        $this->team->refresh();
        $this->assertFalse($this->team->whatsapp_connected);
        $this->assertEquals('new-token', $this->team->whatsapp_token);

        // Assert activity logged
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::WHATSAPP_RELINKED->value,
        ]);
    }

    #[Test]
    public function manager_can_disconnect_account()
    {
        // Setup team with connection
        $this->team->update([
            'whatsapp_connected' => true,
            'whatsapp_account_unique_id' => 'test-unique-id-1234567890123456789012345',
            'whatsapp_account_phone' => '6281234567890',
            'whatsapp_recap_enabled' => true,
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/disconnect');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Assert team disconnected and fields cleared
        $this->team->refresh();
        $this->assertFalse($this->team->whatsapp_connected);
        $this->assertNull($this->team->whatsapp_account_unique_id);
        $this->assertNull($this->team->whatsapp_account_phone);
        $this->assertFalse($this->team->whatsapp_recap_enabled);

        // Assert activity logged
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::WHATSAPP_DISCONNECTED->value,
        ]);
    }

    #[Test]
    public function manager_can_get_settings()
    {
        // Setup team with settings
        $this->team->update([
            'whatsapp_connected' => true,
            'whatsapp_account_phone' => '6281234567890',
            'whatsapp_recipient_phone' => '6289876543210',
            'whatsapp_recap_time' => '22:00:00',
            'whatsapp_recap_enabled' => true,
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson('/api/v1/whatsapp/settings');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'connected' => true,
                    'account_phone' => '6281234567890',
                    'recipient_phone' => '6289876543210',
                    'recap_time' => '22:00',
                    'recap_enabled' => true,
                ],
            ]);
    }

    #[Test]
    public function manager_can_update_settings()
    {
        $response = $this->actingAs($this->manager)
            ->putJson('/api/v1/whatsapp/settings', [
                'whatsapp_recipient_phone' => '628123456789',
                'whatsapp_recap_time' => '23:00',
                'whatsapp_recap_enabled' => true,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'recipient_phone' => '628123456789',
                    'recap_time' => '23:00',
                    'recap_enabled' => true,
                ],
            ]);

        // Assert team updated
        $this->team->refresh();
        $this->assertEquals('628123456789', $this->team->whatsapp_recipient_phone);
        $this->assertEquals('23:00:00', $this->team->whatsapp_recap_time);
        $this->assertTrue($this->team->whatsapp_recap_enabled);

        // Assert activity logged
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::WHATSAPP_SETTINGS_UPDATED->value,
        ]);
    }

    #[Test]
    public function manager_can_send_test_message()
    {
        // Setup team with connection
        $this->team->update([
            'whatsapp_api_secret' => encrypt('test-api-secret'),
            'whatsapp_account_unique_id' => 'test-unique-id-1234567890123456789012345',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);

        // Mock WhatsApp Gateway API response
        Http::fake([
            'whatsapp.perekonomian.id/api/send/whatsapp' => Http::response([
                'status' => 200,
            ], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/test-send', [
                'message' => 'Test message',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Assert activity logged
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::WHATSAPP_TEST_SENT->value,
        ]);
    }

    #[Test]
    public function manager_can_preview_recap()
    {
        $response = $this->actingAs($this->manager)
            ->getJson('/api/v1/whatsapp/preview-recap');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'message',
                ],
            ]);
    }

    #[Test]
    public function recap_excludes_disabled_employees()
    {
        User::factory()->create([
            'name' => 'Active Bob',
            'email' => 'bob-active@test.com',
            'role' => UserRole::EMPLOYEE,
            'team_id' => $this->team->id,
            'is_disabled' => false,
        ]);

        User::factory()->create([
            'name' => 'Disabled Alice',
            'email' => 'alice-disabled@test.com',
            'role' => UserRole::EMPLOYEE,
            'team_id' => $this->team->id,
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson('/api/v1/whatsapp/preview-recap');

        $response->assertStatus(200);
        $message = $response->json('data.message');
        $this->assertStringContainsString('Active Bob', $message);
        $this->assertStringNotContainsString('Disabled Alice', $message);
    }

    #[Test]
    public function employee_cannot_access_whatsapp_endpoints()
    {
        $response = $this->actingAs($this->employee)
            ->postJson('/api/v1/whatsapp/create-link', [
                'api_secret' => 'test-secret',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function validation_fails_with_invalid_phone_format()
    {
        $response = $this->actingAs($this->manager)
            ->putJson('/api/v1/whatsapp/settings', [
                'whatsapp_recipient_phone' => 'invalid-phone',
                'whatsapp_recap_time' => '22:00',
                'whatsapp_recap_enabled' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_recipient_phone']);
    }

    #[Test]
    public function validation_fails_with_short_api_secret()
    {
        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/create-link', [
                'api_secret' => 'short',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['api_secret']);
    }

    #[Test]
    public function validation_fails_with_short_unique_id()
    {
        $response = $this->actingAs($this->manager)
            ->postJson('/api/v1/whatsapp/link-existing', [
                'api_secret' => 'valid-api-secret',
                'unique_id' => 'short',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['unique_id']);
    }

    #[Test]
    public function phone_number_formatting_works_correctly()
    {
        // Mock API
        Http::fake([
            'whatsapp.perekonomian.id/api/get/wa.accounts*' => Http::response([
                'status' => 200,
                'data' => [
                    [
                        'unique' => 'test-unique-id-1234567890123456789012345',
                        'phone' => '6281234567890',
                        'status' => 'connected',
                    ],
                ],
            ], 200),
        ]);

        // Test with 08xxx format
        $response = $this->actingAs($this->manager)
            ->putJson('/api/v1/whatsapp/settings', [
                'whatsapp_recipient_phone' => '085608020200',
                'whatsapp_recap_time' => '22:00',
                'whatsapp_recap_enabled' => true,
            ]);

        $response->assertStatus(200);

        // Test with 628xxx format
        $response = $this->actingAs($this->manager)
            ->putJson('/api/v1/whatsapp/settings', [
                'whatsapp_recipient_phone' => '6285608020200',
                'whatsapp_recap_time' => '22:00',
                'whatsapp_recap_enabled' => true,
            ]);

        $response->assertStatus(200);
    }
}
