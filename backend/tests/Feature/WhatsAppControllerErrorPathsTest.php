<?php

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use App\Services\WhatsAppRecapService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

/*
|--------------------------------------------------------------------------
| WhatsAppController - error paths, authorization, isolation and edge cases
|--------------------------------------------------------------------------
| Complements WhatsAppControllerTest (happy paths). The WhatsApp gateway is
| always faked; real network calls are forbidden.
*/

uses(CreatesTeamUsers::class);

const WA_CEP_GW = 'https://whatsapp.perekonomian.id/api';
const WA_CEP_UNIQUE = 'unique-account-id-123456';

/** Bearer header for a user. */
function waCepHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

/** A super admin has no team, which exercises the "team not found" branches. */
function waCepSuperAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'team_id' => null,
        'leave_quota_days' => 0,
    ]);
}

/** Put a team into a fully linked, recap-ready WhatsApp state. */
function waCepConnect(Team $team, array $overrides = []): Team
{
    $team->update(array_merge([
        'whatsapp_api_secret' => 'team-secret-123456',
        'whatsapp_account_unique_id' => WA_CEP_UNIQUE,
        'whatsapp_account_phone' => '6281234567890',
        'whatsapp_account_name' => 'Team Account',
        'whatsapp_connected' => true,
        'whatsapp_connected_at' => Carbon::parse('2025-01-01 00:00:00'),
        'whatsapp_recipient_phone' => '081298765432',
        'whatsapp_recap_enabled' => true,
    ], $overrides));

    return $team->refresh();
}

/** Another team that must never be affected by the manager under test. */
function waCepOtherTeam(array $overrides = []): Team
{
    return waCepConnect(Team::factory()->create(['name' => 'Other Team']), array_merge([
        'whatsapp_api_secret' => 'other-secret-654321',
        'whatsapp_account_unique_id' => 'other-account-id-654321',
        'whatsapp_account_phone' => '6289999999999',
        'whatsapp_recipient_phone' => '085555555555',
    ], $overrides));
}

function waCepFakeQr(string $endpoint = 'create/wa.link', string $token = 'tok-123'): void
{
    Http::fake([
        WA_CEP_GW.'/'.$endpoint.'*' => Http::response([
            'status' => 200,
            'data' => [
                'qrstring' => 'qr-string',
                'qrimagelink' => 'https://example.com/qr.png',
                'infolink' => 'https://example.com/info?token='.$token,
            ],
        ]),
    ]);
}

function waCepFakeInfo(?string $wid = '6281234567890:1@s.whatsapp.net', string $unique = WA_CEP_UNIQUE): void
{
    Http::fake([
        WA_CEP_GW.'/get/wa.info*' => Http::response([
            'status' => 200,
            'data' => $wid === null ? [] : ['wid' => $wid, 'unique' => $unique],
        ]),
    ]);
}

function waCepFakeAccounts(array $accounts): void
{
    Http::fake([WA_CEP_GW.'/get/wa.accounts*' => Http::response(['status' => 200, 'data' => $accounts])]);
}

function waCepFakeSendOk(): void
{
    Http::fake([WA_CEP_GW.'/send/whatsapp' => Http::response(['status' => 200])]);
}

/** Name => contents map of the multipart parts of an outgoing gateway request. */
function waCepFields(Request $request): array
{
    return collect($request->data())->pluck('contents', 'name')->all();
}

/** Raw (still encrypted) api secret as stored in the database. */
function waCepRawSecret(Team $team): ?string
{
    return DB::table('teams')->where('id', $team->id)->value('whatsapp_api_secret');
}

dataset('wa_cep_endpoints', [
    'create-link' => ['POST', '/api/v1/whatsapp/create-link', ['api_secret' => 'secret-1234567']],
    'link-existing' => ['POST', '/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => 'unique-account-id-123456']],
    'account-info' => ['GET', '/api/v1/whatsapp/account-info?token=tok-123', []],
    'check-connection' => ['GET', '/api/v1/whatsapp/check-connection', []],
    'confirm-link' => ['POST', '/api/v1/whatsapp/confirm-link', ['token' => 'tok-123']],
    'relink' => ['POST', '/api/v1/whatsapp/relink', []],
    'disconnect' => ['POST', '/api/v1/whatsapp/disconnect', []],
    'get settings' => ['GET', '/api/v1/whatsapp/settings', []],
    'update settings' => ['PUT', '/api/v1/whatsapp/settings', ['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true]],
    'test-send' => ['POST', '/api/v1/whatsapp/test-send', []],
    'preview-recap' => ['GET', '/api/v1/whatsapp/preview-recap', []],
    'send-recap' => ['POST', '/api/v1/whatsapp/send-recap', []],
]);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->manager = $this->createManager(['name' => 'Manager One']);
    $this->employee = $this->createEmployee(['name' => 'Employee One']);
});

/* ------------------------------------------------------------------ */
/* Authentication / authorization / team resolution */
/* ------------------------------------------------------------------ */

it('rejects unauthenticated requests on every WhatsApp endpoint', function (string $method, string $uri, array $payload) {
    Http::fake();

    $this->json($method, $uri, $payload)->assertUnauthorized();

    Http::assertNothingSent();
})->with('wa_cep_endpoints');

it('forbids employees on every WhatsApp endpoint without contacting the gateway', function (string $method, string $uri, array $payload) {
    Http::fake();

    $this->withHeaders(waCepHeaders($this->employee))
        ->json($method, $uri, $payload)
        ->assertForbidden();

    Http::assertNothingSent();
})->with('wa_cep_endpoints');

it('forbids a manager who does not belong to a team', function () {
    $orphan = User::factory()->create(['role' => UserRole::MANAGER, 'team_id' => null]);

    $this->withHeaders(waCepHeaders($orphan))
        ->getJson('/api/v1/whatsapp/settings')
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'User tidak tergabung dalam tim']);
});

it('forbids a manager whose team is inactive', function () {
    $this->team->update(['is_active' => false]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/create-link', ['api_secret' => 'secret-1234567'])
        ->assertForbidden()
        ->assertJson(['success' => false]);
});

it('answers with a team-not-found error when a team-less super admin calls team endpoints', function (string $method, string $uri, array $payload, int $status, string $message) {
    Http::fake();

    $this->withHeaders(waCepHeaders(waCepSuperAdmin()))
        ->json($method, $uri, $payload)
        ->assertStatus($status)
        ->assertExactJson(['success' => false, 'message' => $message]);

    Http::assertNothingSent();
})->with([
    'create-link' => ['POST', '/api/v1/whatsapp/create-link', ['api_secret' => 'secret-1234567'], 404, 'Tim tidak ditemukan'],
    'link-existing' => ['POST', '/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => 'unique-account-id-123456'], 404, 'Tim tidak ditemukan'],
    'check-connection' => ['GET', '/api/v1/whatsapp/check-connection', [], 400, 'API Secret belum dikonfigurasi'],
    'confirm-link' => ['POST', '/api/v1/whatsapp/confirm-link', ['token' => 'tok-123'], 404, 'Tim tidak ditemukan'],
    'relink' => ['POST', '/api/v1/whatsapp/relink', [], 404, 'Tim tidak ditemukan'],
    'disconnect' => ['POST', '/api/v1/whatsapp/disconnect', [], 404, 'Tim tidak ditemukan'],
    'get settings' => ['GET', '/api/v1/whatsapp/settings', [], 404, 'Tim tidak ditemukan'],
    'update settings' => ['PUT', '/api/v1/whatsapp/settings', ['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true], 404, 'Tim tidak ditemukan'],
    'test-send' => ['POST', '/api/v1/whatsapp/test-send', [], 404, 'Tim tidak ditemukan'],
    'preview-recap' => ['GET', '/api/v1/whatsapp/preview-recap', [], 404, 'Tim tidak ditemukan'],
    'send-recap' => ['POST', '/api/v1/whatsapp/send-recap', [], 404, 'Tim tidak ditemukan'],
]);

/* ------------------------------------------------------------------ */
/* Unexpected persistence failures are turned into safe 500 responses */
/* ------------------------------------------------------------------ */

it('returns a controlled 500 when saving the team fails', function (string $method, string $uri, array $payload, string $message, Closure $prepare) {
    $prepare($this->team);
    Team::updating(fn () => throw new RuntimeException('db down'));
    Log::spy();

    $this->withHeaders(waCepHeaders($this->manager))
        ->json($method, $uri, $payload)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => $message]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $logged) => str_contains($logged, 'db down'));
})->with([
    'create-link' => [
        'POST', '/api/v1/whatsapp/create-link', ['api_secret' => 'secret-1234567'],
        'Gagal membuat QR Code: db down',
        fn (Team $team) => waCepFakeQr(),
    ],
    'link-existing' => [
        'POST', '/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => WA_CEP_UNIQUE],
        'Gagal menghubungkan WhatsApp: db down',
        fn (Team $team) => waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'status' => 'connected']]),
    ],
    'confirm-link' => [
        'POST', '/api/v1/whatsapp/confirm-link', ['token' => 'tok-123'],
        'Gagal menghubungkan WhatsApp',
        fn (Team $team) => waCepFakeInfo(),
    ],
    'check-connection' => [
        'GET', '/api/v1/whatsapp/check-connection', [],
        'Gagal memeriksa koneksi',
        function (Team $team) {
            waCepConnect($team, ['whatsapp_account_unique_id' => 'stale-account-id-000000']);
            waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'status' => 'connected']]);
        },
    ],
    'relink' => [
        'POST', '/api/v1/whatsapp/relink', [],
        'Gagal membuat QR Code relink',
        function (Team $team) {
            waCepConnect($team);
            waCepFakeQr('create/wa.relink');
        },
    ],
    'disconnect' => [
        'POST', '/api/v1/whatsapp/disconnect', [],
        'Gagal memutuskan WhatsApp',
        fn (Team $team) => waCepConnect($team),
    ],
    'update settings' => [
        'PUT', '/api/v1/whatsapp/settings', ['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true],
        'Gagal memperbarui pengaturan WhatsApp',
        fn (Team $team) => null,
    ],
]);

/* ------------------------------------------------------------------ */
/* createLink */
/* ------------------------------------------------------------------ */

it('reports a gateway outage on create-link and leaves the team untouched', function () {
    Http::fake([WA_CEP_GW.'/create/wa.link*' => Http::response('upstream exploded', 502)]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/create-link', ['api_secret' => 'secret-1234567'])
        ->assertStatus(500)
        ->assertJson([
            'success' => false,
            'message' => 'Gagal membuat QR Code: Failed to create QR link: upstream exploded',
        ]);

    $team = $this->team->fresh();
    expect($team->whatsapp_api_secret)->toBeNull()
        ->and($team->whatsapp_token)->toBeNull();
});

it('surfaces the gateway message when create-link is rejected', function () {
    Http::fake([
        WA_CEP_GW.'/create/wa.link*' => Http::response(['status' => 403, 'message' => 'Invalid secret']),
    ]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/create-link', ['api_secret' => 'secret-1234567'])
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Gagal membuat QR Code: Invalid secret']);

    expect($this->team->fresh()->whatsapp_api_secret)->toBeNull();
});

it('rejects create-link without an api secret', function () {
    Http::fake();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/create-link', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['api_secret'])
        ->assertJsonPath('errors.api_secret.0', 'API Secret harus diisi');

    Http::assertNothingSent();
});

it('stores the api secret encrypted and only on the callers team when creating a link', function () {
    waCepFakeQr();
    $other = waCepOtherTeam();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/create-link', ['api_secret' => 'brand-new-secret'])
        ->assertOk();

    $team = $this->team->fresh();
    expect($team->whatsapp_api_secret)->toBe('brand-new-secret')
        ->and(waCepRawSecret($team))->not->toBe('brand-new-secret')
        ->and($team->whatsapp_token)->toBe('tok-123');

    $other->refresh();
    expect($other->whatsapp_api_secret)->toBe('other-secret-654321')
        ->and($other->whatsapp_token)->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'secret=brand-new-secret'));
});

/* ------------------------------------------------------------------ */
/* accountInfo */
/* ------------------------------------------------------------------ */

it('requires a token to poll account info', function (string $uri) {
    Http::fake();

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson($uri)
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'message' => 'Token diperlukan']);

    Http::assertNothingSent();
})->with([
    'no token' => '/api/v1/whatsapp/account-info',
    'empty token' => '/api/v1/whatsapp/account-info?token=',
]);

it('tells the client to keep waiting while the QR code is not scanned', function () {
    waCepFakeInfo(null);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/account-info?token=tok-123')
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => null, 'message' => 'Menunggu scan QR Code']);
});

it('reports a detected account on account-info without saving anything', function () {
    waCepFakeInfo('6281234567890:7@s.whatsapp.net', 'detected-unique-id-1234');

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/account-info?token=tok-123')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['unique_id' => 'detected-unique-id-1234', 'phone' => '6281234567890'],
            'message' => 'Akun WhatsApp terdeteksi',
        ]);

    $team = $this->team->fresh();
    expect($team->whatsapp_connected)->toBeFalse()
        ->and($team->whatsapp_account_unique_id)->toBeNull();
});

it('hides gateway details when polling account info fails', function () {
    Http::fake([WA_CEP_GW.'/get/wa.info*' => Http::response('internal gateway secret detail', 500)]);

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/account-info?token=tok-123')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal mengambil informasi akun']);

    expect($response->getContent())->not->toContain('internal gateway secret detail');
});

/* ------------------------------------------------------------------ */
/* checkConnection */
/* ------------------------------------------------------------------ */

it('requires an api secret before checking the connection', function () {
    Http::fake();

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'message' => 'API Secret belum dikonfigurasi']);

    Http::assertNothingSent();
});

it('reports no linked account while every gateway account is disconnected', function () {
    waCepConnect($this->team, [
        'whatsapp_connected' => false,
        'whatsapp_account_unique_id' => null,
        'whatsapp_connected_at' => null,
    ]);
    waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'status' => 'disconnected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => null, 'message' => 'Belum ada akun terhubung']);

    expect($this->team->fresh()->whatsapp_connected)->toBeFalse();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_CONNECTED->value]);
});

it('does not rewrite or re-log an account that is already saved', function () {
    $team = waCepConnect($this->team, ['whatsapp_token' => 'leftover-token']);
    waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'name' => 'Renamed', 'status' => 'connected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['unique_id' => WA_CEP_UNIQUE, 'name' => 'Renamed'],
            'message' => 'Akun WhatsApp ditemukan',
        ]);

    $fresh = $team->fresh();
    expect($fresh->whatsapp_account_name)->toBe('Team Account')
        ->and($fresh->whatsapp_connected_at->toDateTimeString())->toBe('2025-01-01 00:00:00');
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_CONNECTED->value]);
});

it('saves the new account when the gateway reports a different one', function () {
    $team = waCepConnect($this->team, ['whatsapp_token' => 'leftover-token']);
    waCepFakeAccounts([['unique' => 'replacement-account-id-9', 'phone' => '6285555000111', 'name' => 'New Phone', 'status' => 'connected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertOk()
        ->assertJsonPath('data.unique_id', 'replacement-account-id-9');

    $fresh = $team->fresh();
    expect($fresh->whatsapp_account_unique_id)->toBe('replacement-account-id-9')
        ->and($fresh->whatsapp_account_phone)->toBe('6285555000111')
        ->and($fresh->whatsapp_account_name)->toBe('New Phone')
        ->and($fresh->whatsapp_connected)->toBeTrue()
        ->and($fresh->whatsapp_token)->toBeNull();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_CONNECTED->value,
        'description' => 'WhatsApp account connected: 6285555000111',
    ]);
});

it('marks a relinked account as connected again once the gateway reports it', function () {
    // State right after POST /relink: same account id but flagged disconnected.
    $team = waCepConnect($this->team, ['whatsapp_connected' => false, 'whatsapp_token' => 'relink-token']);
    waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'status' => 'connected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertOk();

    $fresh = $team->fresh();
    expect($fresh->whatsapp_connected)->toBeTrue()
        ->and($fresh->whatsapp_token)->toBeNull();
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_CONNECTED->value,
    ]);
});

it('hides gateway details when checking the connection fails', function (Closure $gatewayResponse) {
    waCepConnect($this->team);
    Http::fake([WA_CEP_GW.'/get/wa.accounts*' => $gatewayResponse()]);

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal memeriksa koneksi']);

    expect($response->getContent())->not->toContain('upstream exploded')->not->toContain('Invalid secret');
})->with([
    'http error' => [fn () => Http::response('upstream exploded', 502)],
    'secret rejected' => [fn () => Http::response(['status' => 401, 'message' => 'Invalid secret'])],
]);

it('queries the gateway with the callers own team secret only', function () {
    waCepConnect($this->team, ['whatsapp_api_secret' => 'secret-of-my-team']);
    waCepOtherTeam(['whatsapp_api_secret' => 'secret-of-other-team']);
    waCepFakeAccounts([]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/check-connection')
        ->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'secret=secret-of-my-team'));
});

/* ------------------------------------------------------------------ */
/* confirmLink */
/* ------------------------------------------------------------------ */

it('requires a token to confirm a link', function () {
    Http::fake();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/confirm-link', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token'])
        ->assertJsonPath('errors.token.0', 'Token harus diisi');

    Http::assertNothingSent();
});

it('refuses to confirm a link until the account is actually linked', function () {
    $this->team->update(['whatsapp_token' => 'pending-token']);
    waCepFakeInfo(null);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/confirm-link', ['token' => 'pending-token'])
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'message' => 'Akun WhatsApp belum terhubung']);

    $team = $this->team->fresh();
    expect($team->whatsapp_connected)->toBeFalse()
        ->and($team->whatsapp_token)->toBe('pending-token');
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_CONNECTED->value]);
});

it('hides gateway details when confirming a link fails', function () {
    Http::fake([WA_CEP_GW.'/get/wa.info*' => Http::response('boom', 500)]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/confirm-link', ['token' => 'tok-123'])
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal menghubungkan WhatsApp']);

    expect($this->team->fresh()->whatsapp_connected)->toBeFalse();
});

it('returns the saved settings and logs the connection when a link is confirmed', function () {
    $this->team->update(['whatsapp_api_secret' => 'secret-1234567', 'whatsapp_token' => 'tok-123']);
    waCepFakeInfo('6281234567890:1@s.whatsapp.net', 'confirmed-unique-id-1234');

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/confirm-link', ['token' => 'tok-123'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'WhatsApp berhasil terhubung',
            'data' => [
                'connected' => true,
                'account_unique_id' => 'confirmed-unique-id-1234',
                'account_phone' => '6281234567890',
                'account_name' => '6281234567890',
                'recap_enabled' => false,
                'can_send_recap' => false,
            ],
        ])
        ->assertJsonPath('data.connected_at', fn ($value) => is_string($value) && $value !== '');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_CONNECTED->value,
        'description' => 'WhatsApp account connected: 6281234567890',
    ]);
});

/* ------------------------------------------------------------------ */
/* linkExisting */
/* ------------------------------------------------------------------ */

it('requires both api secret and unique id to link an existing account', function () {
    Http::fake();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/link-existing', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['api_secret', 'unique_id'])
        ->assertJsonPath('errors.unique_id.0', 'Unique ID harus diisi');

    Http::assertNothingSent();
});

it('does not link an account whose gateway status is not connected', function () {
    waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'status' => 'disconnected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => WA_CEP_UNIQUE])
        ->assertStatus(400)
        ->assertExactJson([
            'success' => false,
            'message' => 'Account tidak dalam status connected. Status: disconnected',
        ]);

    $team = $this->team->fresh();
    expect($team->whatsapp_connected)->toBeFalse()
        ->and($team->whatsapp_api_secret)->toBeNull()
        ->and($team->whatsapp_account_unique_id)->toBeNull();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_CONNECTED->value]);
});

it('does not save anything when the unique id is unknown to the gateway', function () {
    waCepFakeAccounts([['unique' => 'someone-elses-account-id', 'phone' => '6281234567890', 'status' => 'connected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => WA_CEP_UNIQUE])
        ->assertStatus(404);

    $team = $this->team->fresh();
    expect($team->whatsapp_api_secret)->toBeNull()
        ->and($team->whatsapp_connected)->toBeFalse();
});

it('surfaces the gateway failure reason when linking an existing account fails', function (Closure $gatewayResponse, string $expectedMessage) {
    Http::fake([WA_CEP_GW.'/get/wa.accounts*' => $gatewayResponse()]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => WA_CEP_UNIQUE])
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => $expectedMessage]);

    expect($this->team->fresh()->whatsapp_api_secret)->toBeNull();
})->with([
    'http error' => [
        fn () => Http::response('upstream exploded', 502),
        'Gagal menghubungkan WhatsApp: Failed to get accounts: upstream exploded',
    ],
    'secret rejected' => [
        fn () => Http::response(['status' => 401, 'message' => 'Invalid secret']),
        'Gagal menghubungkan WhatsApp: Invalid secret',
    ],
]);

it('links an existing account, encrypts the secret, falls back to the phone as name and logs it', function () {
    $this->team->update(['whatsapp_token' => 'stale-token']);
    waCepFakeAccounts([['unique' => WA_CEP_UNIQUE, 'phone' => '6281234567890', 'status' => 'connected']]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/link-existing', ['api_secret' => 'secret-1234567', 'unique_id' => WA_CEP_UNIQUE])
        ->assertOk()
        ->assertJsonPath('data.account_name', '6281234567890')
        ->assertJsonPath('data.connected', true)
        ->assertJsonMissingPath('data.api_secret');

    $team = $this->team->fresh();
    expect($team->whatsapp_api_secret)->toBe('secret-1234567')
        ->and(waCepRawSecret($team))->not->toBe('secret-1234567')
        ->and($team->whatsapp_token)->toBeNull()
        ->and($team->whatsapp_connected_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_CONNECTED->value,
        'description' => 'WhatsApp account linked (existing): 6281234567890',
    ]);
});

/* ------------------------------------------------------------------ */
/* relink */
/* ------------------------------------------------------------------ */

it('refuses to relink an account that was never linked', function (array $overrides) {
    Http::fake();
    waCepConnect($this->team, $overrides);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/relink')
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'message' => 'WhatsApp belum pernah terhubung']);

    Http::assertNothingSent();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_RELINKED->value]);
})->with([
    'no api secret' => [['whatsapp_api_secret' => null]],
    'no account id' => [['whatsapp_account_unique_id' => null]],
    'neither' => [['whatsapp_api_secret' => null, 'whatsapp_account_unique_id' => null]],
]);

it('keeps the team connected when the relink request fails at the gateway', function (Closure $gatewayResponse) {
    $team = waCepConnect($this->team, ['whatsapp_token' => 'old-token']);
    Http::fake([WA_CEP_GW.'/create/wa.relink*' => $gatewayResponse()]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/relink')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal membuat QR Code relink']);

    $fresh = $team->fresh();
    expect($fresh->whatsapp_connected)->toBeTrue()
        ->and($fresh->whatsapp_token)->toBe('old-token');
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_RELINKED->value]);
})->with([
    'http error' => [fn () => Http::response('server down', 503)],
    'gateway rejection' => [fn () => Http::response(['status' => 404, 'message' => 'Account unknown'])],
]);

it('relinks using only the callers own team credentials', function () {
    waCepConnect($this->team, [
        'whatsapp_api_secret' => 'secret-of-my-team',
        'whatsapp_account_unique_id' => 'account-of-my-team-12345',
    ]);
    waCepOtherTeam();
    waCepFakeQr('create/wa.relink', 'relink-tok');

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/relink')
        ->assertOk()
        ->assertJsonPath('data.token', 'relink-tok');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['secret' => 'secret-of-my-team', 'unique' => 'account-of-my-team-12345'];
    });

    expect(Team::where('name', 'Other Team')->first()->whatsapp_connected)->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* disconnect */
/* ------------------------------------------------------------------ */

it('only disconnects the callers own team', function () {
    waCepConnect($this->team);
    $other = waCepOtherTeam();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/disconnect')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'WhatsApp berhasil diputuskan']);

    expect($this->team->fresh()->whatsapp_connected)->toBeFalse();

    $other->refresh();
    expect($other->whatsapp_connected)->toBeTrue()
        ->and($other->whatsapp_account_unique_id)->toBe('other-account-id-654321')
        ->and($other->whatsapp_recap_enabled)->toBeTrue();
});

it('can disconnect a team that is already disconnected', function () {
    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/disconnect')
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($this->team->fresh()->whatsapp_connected)->toBeFalse();
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_DISCONNECTED->value,
    ]);
});

/* ------------------------------------------------------------------ */
/* getSettings */
/* ------------------------------------------------------------------ */

it('returns default settings for a team that never configured WhatsApp', function () {
    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/settings')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'data' => [
                'connected' => false,
                'connected_at' => null,
                'account_unique_id' => null,
                'account_phone' => null,
                'account_name' => null,
                'recipient_phone' => null,
                'recap_time' => '22:00',
                'recap_enabled' => false,
                'last_sent_at' => null,
                'last_error' => null,
                'can_send_recap' => false,
            ],
        ]);
});

it('exposes only the callers team settings and never the api secret or token', function () {
    waCepConnect($this->team, [
        'whatsapp_api_secret' => 'super-private-secret',
        'whatsapp_token' => 'private-link-token',
        'whatsapp_last_error' => 'Account offline',
    ]);
    waCepOtherTeam();

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/settings')
        ->assertOk()
        ->assertJsonPath('data.account_phone', '6281234567890')
        ->assertJsonPath('data.recipient_phone', '081298765432')
        ->assertJsonPath('data.last_error', 'Account offline')
        ->assertJsonPath('data.can_send_recap', true);

    expect($response->getContent())
        ->not->toContain('super-private-secret')
        ->not->toContain('private-link-token')
        ->not->toContain('6289999999999')
        ->not->toContain('other-account-id-654321');
});

it('reports a controlled 500 when the stored settings cannot be serialised', function () {
    DB::table('teams')->where('id', $this->team->id)->update(['whatsapp_connected_at' => 'not-a-date']);

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/settings')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal mengambil pengaturan WhatsApp']);
});

/* ------------------------------------------------------------------ */
/* updateSettings */
/* ------------------------------------------------------------------ */

it('validates the WhatsApp settings payload', function (array $payload, array $invalidFields) {
    $this->withHeaders(waCepHeaders($this->manager))
        ->putJson('/api/v1/whatsapp/settings', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($invalidFields);

    $team = $this->team->fresh();
    expect($team->whatsapp_recipient_phone)->toBeNull()
        ->and($team->whatsapp_recap_enabled)->toBeFalse();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_SETTINGS_UPDATED->value]);
})->with([
    'everything missing' => [[], ['whatsapp_recipient_phone', 'whatsapp_recap_time', 'whatsapp_recap_enabled']],
    'unknown phone prefix' => [['whatsapp_recipient_phone' => '0712345678', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true], ['whatsapp_recipient_phone']],
    'phone with plus sign' => [['whatsapp_recipient_phone' => '+6281234567890', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true], ['whatsapp_recipient_phone']],
    'phone too short' => [['whatsapp_recipient_phone' => '08123', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true], ['whatsapp_recipient_phone']],
    'phone too long' => [['whatsapp_recipient_phone' => '08123456789012345', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => true], ['whatsapp_recipient_phone']],
    'time out of range' => [['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => '25:00', 'whatsapp_recap_enabled' => true], ['whatsapp_recap_time']],
    'time with seconds' => [['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => '22:00:00', 'whatsapp_recap_enabled' => true], ['whatsapp_recap_time']],
    'time not a time' => [['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => 'night', 'whatsapp_recap_enabled' => true], ['whatsapp_recap_time']],
    'enabled not boolean' => [['whatsapp_recipient_phone' => '081234567890', 'whatsapp_recap_time' => '22:00', 'whatsapp_recap_enabled' => 'maybe'], ['whatsapp_recap_enabled']],
]);

it('can switch the recap off again and keeps the phone as submitted', function () {
    waCepConnect($this->team, ['whatsapp_recipient_phone' => '081111111111', 'whatsapp_recap_enabled' => true]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->putJson('/api/v1/whatsapp/settings', [
            'whatsapp_recipient_phone' => '085608020200',
            'whatsapp_recap_time' => '07:30',
            'whatsapp_recap_enabled' => 0,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Pengaturan WhatsApp berhasil diperbarui',
            'data' => [
                'recipient_phone' => '085608020200',
                'recap_time' => '07:30',
                'recap_enabled' => false,
                'can_send_recap' => false,
            ],
        ]);

    $team = $this->team->fresh();
    expect($team->whatsapp_recap_enabled)->toBeFalse()
        ->and($team->whatsapp_recap_time)->toBe('07:30:00');
});

it('ignores connection fields smuggled into the settings payload', function () {
    $other = waCepOtherTeam();

    $this->withHeaders(waCepHeaders($this->manager))
        ->putJson('/api/v1/whatsapp/settings', [
            'whatsapp_recipient_phone' => '081234567890',
            'whatsapp_recap_time' => '22:00',
            'whatsapp_recap_enabled' => true,
            'whatsapp_connected' => true,
            'whatsapp_api_secret' => 'injected-secret',
            'whatsapp_account_unique_id' => 'injected-account-id',
            'team_id' => $other->id,
            'id' => $other->id,
        ])
        ->assertOk();

    $team = $this->team->fresh();
    expect($team->whatsapp_connected)->toBeFalse()
        ->and($team->whatsapp_api_secret)->toBeNull()
        ->and($team->whatsapp_account_unique_id)->toBeNull()
        ->and($team->whatsapp_recipient_phone)->toBe('081234567890');

    $other->refresh();
    expect($other->whatsapp_recipient_phone)->toBe('085555555555')
        ->and($other->whatsapp_api_secret)->toBe('other-secret-654321');
});

/* ------------------------------------------------------------------ */
/* testSend */
/* ------------------------------------------------------------------ */

it('refuses to send a test message until WhatsApp is fully configured', function (array $overrides) {
    Http::fake();
    waCepConnect($this->team, $overrides);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/test-send', ['message' => 'Hello'])
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'message' => 'WhatsApp belum dikonfigurasi dengan lengkap']);

    Http::assertNothingSent();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_TEST_SENT->value]);
})->with([
    'not connected' => [['whatsapp_connected' => false]],
    'no recipient' => [['whatsapp_recipient_phone' => null]],
    'no api secret' => [['whatsapp_api_secret' => null]],
    'no account id' => [['whatsapp_account_unique_id' => null]],
]);

it('sends the default test message to the normalised recipient using the callers credentials', function () {
    waCepConnect($this->team, [
        'whatsapp_api_secret' => 'secret-of-my-team',
        'whatsapp_account_unique_id' => 'account-of-my-team-12345',
        'whatsapp_recipient_phone' => '081234567890',
    ]);
    waCepOtherTeam();
    waCepFakeSendOk();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/test-send')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Pesan test berhasil dikirim']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => waCepFields($request) === [
        'secret' => 'secret-of-my-team',
        'account' => 'account-of-my-team-12345',
        'recipient' => '6281234567890',
        'type' => 'text',
        'message' => 'Test message from WFH Attendance System',
        'priority' => '2',
    ]);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_TEST_SENT->value,
    ]);
});

it('rejects an invalid test message with a 422 and contacts nobody', function (mixed $message) {
    Http::fake();
    waCepConnect($this->team);

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/test-send', ['message' => $message])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);

    expect($response->getContent())->not->toContain('TypeError')->not->toContain('must be of type');
    Http::assertNothingSent();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_TEST_SENT->value]);
})->with([
    'array' => [['hello']],
    'nested object' => [['a' => ['b' => 1]]],
    'integer' => [12345],
    'boolean' => [true],
    'longer than the limit' => [str_repeat('a', 1001)],
]);

it('falls back to the default test message when the message is null or blank', function (mixed $message) {
    waCepConnect($this->team);
    waCepFakeSendOk();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/test-send', ['message' => $message])
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Pesan test berhasil dikirim']);

    Http::assertSent(fn (Request $request) => waCepFields($request)['message'] === 'Test message from WFH Attendance System');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace only' => ['   '],
]);

it('sends a custom test message of exactly the maximum length', function () {
    waCepConnect($this->team);
    waCepFakeSendOk();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/test-send', ['message' => str_repeat('a', 1000)])
        ->assertOk();

    Http::assertSent(fn (Request $request) => waCepFields($request)['message'] === str_repeat('a', 1000));
});

it('reports gateway failures on test-send and logs no test activity', function (Closure $gatewayResponse, string $expectedMessage) {
    waCepConnect($this->team);
    Http::fake([WA_CEP_GW.'/send/whatsapp' => $gatewayResponse()]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/test-send', ['message' => 'Hello'])
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => $expectedMessage]);

    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_TEST_SENT->value]);
})->with([
    'http error' => [
        fn () => Http::response('rate limited', 429),
        'Gagal mengirim pesan test: Failed to send message: rate limited',
    ],
    'gateway rejection' => [
        fn () => Http::response(['status' => 400, 'message' => 'Account offline']),
        'Gagal mengirim pesan test: Account offline',
    ],
]);

/* ------------------------------------------------------------------ */
/* previewRecap */
/* ------------------------------------------------------------------ */

it('previews the recap for today by default without sending anything', function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-01-10 08:00:00'));

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/preview-recap')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message', fn (string $message) => str_contains($message, 'Daily Attendance Recap')
            && str_contains($message, '10 Jan 2026'));

    Http::assertNothingSent();
});

it('previews the recap for the requested date', function () {
    $this->travelTo(Carbon::parse('2026-01-10 08:00:00'));

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/preview-recap?date=2025-03-15')
        ->assertOk()
        ->assertJsonPath('data.message', fn (string $message) => str_contains($message, '15 Mar 2025')
            && ! str_contains($message, '10 Jan 2026'));
});

dataset('wa_cep_bad_dates', [
    'garbage' => 'garbage',
    'impossible calendar date' => '2026-02-30',
    'day first' => '15-03-2025',
    'not zero padded' => '2025-3-5',
    'with a time' => '2025-03-15 10:00:00',
    'zero' => '0',
    'array' => [['date[]' => 'x']],
]);

/** Query string for a bad date value (arrays come as ['date[]' => ...]). */
function waCepDateQuery(string|array $bad): string
{
    return is_array($bad) ? http_build_query($bad) : 'date='.urlencode($bad);
}

it('rejects a malformed preview date with a 422 instead of a 500', function (string|array $bad) {
    Http::fake();

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/preview-recap?'.waCepDateQuery($bad))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date']);

    expect($response->getContent())->not->toContain('Gagal membuat preview recap')->not->toContain('Could not parse');
    Http::assertNothingSent();
})->with('wa_cep_bad_dates');

it('rejects a malformed send-recap date with a 422 and sends nothing', function (string|array $bad) {
    Http::fake();
    waCepConnect($this->team);

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap?'.waCepDateQuery($bad))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date']);

    expect($response->getContent())->not->toContain('Gagal mengirim recap')->not->toContain('Could not parse');
    Http::assertNothingSent();
    expect($this->team->fresh()->whatsapp_last_sent_at)->toBeNull();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_RECAP_SENT->value]);
})->with('wa_cep_bad_dates');

it('treats an empty date parameter as today', function () {
    $this->travelTo(Carbon::parse('2026-01-10 08:00:00'));

    $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/preview-recap?date=')
        ->assertOk()
        ->assertJsonPath('data.message', fn (string $message) => str_contains($message, '10 Jan 2026'));
});

it('only includes employees of the callers own team in the preview', function () {
    $this->createEmployee(['name' => 'Alice Ownteam']);
    $other = waCepOtherTeam();
    User::factory()->create(['team_id' => $other->id, 'role' => UserRole::EMPLOYEE, 'name' => 'Zed Otherteam']);

    $message = $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/preview-recap')
        ->assertOk()
        ->json('data.message');

    expect($message)
        ->toContain('Alice Ownteam')
        ->toContain('Employee One')
        ->not->toContain('Zed Otherteam')
        ->not->toContain('Other Team');
});

it('hides internal details when building the preview fails', function () {
    $this->mock(WhatsAppRecapService::class)
        ->shouldReceive('previewRecap')
        ->once()
        ->andThrow(new RuntimeException('sql: secret internal detail'));

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->getJson('/api/v1/whatsapp/preview-recap')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal membuat preview recap']);

    expect($response->getContent())->not->toContain('secret internal detail');
});

/* ------------------------------------------------------------------ */
/* sendRecap */
/* ------------------------------------------------------------------ */

it('refuses to send a recap until WhatsApp is configured and enabled', function (array $overrides) {
    Http::fake();
    waCepConnect($this->team, $overrides);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap')
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'message' => 'WhatsApp belum dikonfigurasi dengan lengkap']);

    Http::assertNothingSent();
    expect($this->team->fresh()->whatsapp_last_sent_at)->toBeNull();
})->with([
    'recap disabled' => [['whatsapp_recap_enabled' => false]],
    'not connected' => [['whatsapp_connected' => false]],
    'no recipient' => [['whatsapp_recipient_phone' => null]],
    'no api secret' => [['whatsapp_api_secret' => null]],
    'no account id' => [['whatsapp_account_unique_id' => null]],
]);

it('sends the recap for the callers team to the normalised recipient and records the send', function () {
    $this->travelTo(Carbon::parse('2026-01-10 08:00:00'));
    waCepConnect($this->team, [
        'name' => 'Own Team Name',
        'whatsapp_api_secret' => 'secret-of-my-team',
        'whatsapp_account_unique_id' => 'account-of-my-team-12345',
        'whatsapp_recipient_phone' => '081234567890',
        'whatsapp_last_error' => 'older failure',
    ]);
    $this->createEmployee(['name' => 'Alice Ownteam']);
    $other = waCepOtherTeam();
    User::factory()->create(['team_id' => $other->id, 'role' => UserRole::EMPLOYEE, 'name' => 'Zed Otherteam']);
    waCepFakeSendOk();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Recap berhasil dikirim via WhatsApp']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $fields = waCepFields($request);

        return $fields['secret'] === 'secret-of-my-team'
            && $fields['account'] === 'account-of-my-team-12345'
            && $fields['recipient'] === '6281234567890'
            && str_contains($fields['message'], 'Daily Attendance Recap')
            && str_contains($fields['message'], '10 Jan 2026')
            && str_contains($fields['message'], 'Alice Ownteam')
            && ! str_contains($fields['message'], 'Zed Otherteam');
    });

    $team = $this->team->fresh();
    expect($team->whatsapp_last_sent_at->toDateTimeString())->toBe('2026-01-10 08:00:00')
        ->and($team->whatsapp_last_error)->toBeNull();
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->manager->id,
        'action' => ActivityType::WHATSAPP_RECAP_SENT->value,
    ]);
    expect(Team::where('name', 'Other Team')->first()->whatsapp_last_sent_at)->toBeNull();
});

it('sends the recap for the requested date', function () {
    $this->travelTo(Carbon::parse('2026-01-10 08:00:00'));
    waCepConnect($this->team);
    waCepFakeSendOk();

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap?date=2025-03-15')
        ->assertOk();

    Http::assertSent(fn (Request $request) => str_contains(waCepFields($request)['message'], '15 Mar 2025'));
});

it('stores the gateway failure on the team and reports it when sending the recap fails', function (Closure $gatewayResponse, string $expectedError) {
    $this->travelTo(Carbon::parse('2026-01-10 08:00:00'));
    waCepConnect($this->team);
    Http::fake([WA_CEP_GW.'/send/whatsapp' => $gatewayResponse()]);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal mengirim recap: '.$expectedError]);

    $team = $this->team->fresh();
    expect($team->whatsapp_last_error)->toBe($expectedError)
        ->and($team->whatsapp_last_sent_at)->toBeNull();
    $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_RECAP_SENT->value]);
})->with([
    'http error' => [
        fn () => Http::response('upstream exploded', 502),
        'Failed to send message: upstream exploded',
    ],
    'gateway rejection' => [
        fn () => Http::response(['status' => 400, 'message' => 'Account offline']),
        'Account offline',
    ],
]);

it('shows the stored error when the recap service reports a failed send', function () {
    waCepConnect($this->team);
    $this->mock(WhatsAppRecapService::class)
        ->shouldReceive('sendRecap')
        ->once()
        ->andReturnUsing(function (Team $team) {
            $team->whatsapp_last_error = 'Service said no';

            return false;
        });

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal mengirim recap: Service said no']);
});

it('hides internal details when the recap service throws unexpectedly', function () {
    waCepConnect($this->team);
    $this->mock(WhatsAppRecapService::class)
        ->shouldReceive('sendRecap')
        ->once()
        ->andThrow(new RuntimeException('sql: secret internal detail'));

    $response = $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap')
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal mengirim recap']);

    expect($response->getContent())->not->toContain('secret internal detail');
});

it('passes the requested date to the recap service as a Carbon instance', function () {
    waCepConnect($this->team);
    $this->mock(WhatsAppRecapService::class)
        ->shouldReceive('sendRecap')
        ->once()
        ->withArgs(fn (Team $team, Carbon $date) => $team->is($this->team) && $date->toDateString() === '2025-03-15')
        ->andReturn(true);

    $this->withHeaders(waCepHeaders($this->manager))
        ->postJson('/api/v1/whatsapp/send-recap?date=2025-03-15')
        ->assertOk()
        ->assertJson(['success' => true]);
});
