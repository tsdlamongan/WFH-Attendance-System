<?php

use App\Services\WhatsAppGatewayService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| WhatsAppGatewayService
|--------------------------------------------------------------------------
| The service is the only code talking to the external WhatsApp gateway, so
| every HTTP call is faked and stray requests are forbidden.
*/

const WA_GW_BASE = 'https://whatsapp.perekonomian.id/api';

/**
 * Build a gateway "QR link" payload (used by create/relink endpoints).
 */
function waGwQrPayload(string $infolink = 'https://example.com/info?token=tok-123'): array
{
    return [
        'status' => 200,
        'data' => [
            'qrstring' => 'qr-string',
            'qrimagelink' => 'https://example.com/qr.png',
            'infolink' => $infolink,
        ],
    ];
}

/**
 * Convert the multipart parts of an outgoing request into a name => contents map.
 */
function waGwMultipartFields(Request $request): array
{
    return collect($request->data())->pluck('contents', 'name')->all();
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new WhatsAppGatewayService;
});

/* ------------------------------------------------------------------ */
/* createQRLink */
/* ------------------------------------------------------------------ */

it('creates a QR link and extracts the token from the info link', function () {
    Http::fake([WA_GW_BASE.'/create/wa.link*' => Http::response(waGwQrPayload())]);

    $result = $this->gateway->createQRLink('my-secret-123');

    expect($result)->toBe([
        'qr_string' => 'qr-string',
        'qr_image_url' => 'https://example.com/qr.png',
        'token' => 'tok-123',
    ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), WA_GW_BASE.'/create/wa.link')
            && $query === ['secret' => 'my-secret-123'];
    });
});

it('sends the optional server id when creating a QR link', function () {
    Http::fake([WA_GW_BASE.'/create/wa.link*' => Http::response(waGwQrPayload())]);

    $this->gateway->createQRLink('my-secret-123', 7);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['secret'] === 'my-secret-123' && $query['sid'] === '7';
    });
});

it('returns a null token when the info link carries no token parameter', function () {
    Http::fake([
        WA_GW_BASE.'/create/wa.link*' => Http::response(waGwQrPayload('https://example.com/info?foo=bar')),
    ]);

    expect($this->gateway->createQRLink('my-secret-123')['token'])->toBeNull();
});

it('throws with the gateway body when creating a QR link gets an HTTP error', function () {
    Http::fake([WA_GW_BASE.'/create/wa.link*' => Http::response('upstream exploded', 502)]);

    expect(fn () => $this->gateway->createQRLink('my-secret-123'))
        ->toThrow(Exception::class, 'Failed to create QR link: upstream exploded');
});

it('throws the gateway message when creating a QR link returns a non-200 status', function () {
    Http::fake([
        WA_GW_BASE.'/create/wa.link*' => Http::response(['status' => 403, 'message' => 'Invalid secret']),
    ]);

    expect(fn () => $this->gateway->createQRLink('bad-secret-123'))
        ->toThrow(Exception::class, 'Invalid secret');
});

it('falls back to a default message when the gateway status is missing and no message is given', function () {
    Http::fake([WA_GW_BASE.'/create/wa.link*' => Http::response(['data' => []])]);

    expect(fn () => $this->gateway->createQRLink('my-secret-123'))
        ->toThrow(Exception::class, 'Failed to create QR link');
});

it('logs and rethrows connection failures when creating a QR link', function () {
    Http::fake([WA_GW_BASE.'/create/wa.link*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
    Log::spy();

    expect(fn () => $this->gateway->createQRLink('my-secret-123'))
        ->toThrow(ConnectionException::class, 'timed out');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'createQRLink failed') && str_contains($message, 'timed out'));
});

/* ------------------------------------------------------------------ */
/* getAccountInfo */
/* ------------------------------------------------------------------ */

it('parses the phone number out of the wid when fetching account info', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.info*' => Http::response([
            'status' => 200,
            'data' => ['wid' => '6285124740348:9@s.whatsapp.net', 'unique' => 'uniq-abc'],
        ]),
    ]);

    $info = $this->gateway->getAccountInfo('tok-123');

    expect($info)->toBe([
        'unique_id' => 'uniq-abc',
        'phone' => '6285124740348',
        'name' => '6285124740348',
    ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), WA_GW_BASE.'/get/wa.info') && $query === ['token' => 'tok-123'];
    });
});

it('returns null account info while the QR code has not been scanned', function (array $body) {
    Http::fake([WA_GW_BASE.'/get/wa.info*' => Http::response($body)]);

    expect($this->gateway->getAccountInfo('tok-123'))->toBeNull();
})->with([
    'empty data array' => [['status' => 200, 'data' => []]],
    'null data' => [['status' => 200, 'data' => null]],
    'no data key' => [['status' => 200]],
]);

it('returns null phone and name when the wid is malformed or absent', function (array $data, ?string $expectedUnique) {
    Http::fake([WA_GW_BASE.'/get/wa.info*' => Http::response(['status' => 200, 'data' => $data])]);

    $info = $this->gateway->getAccountInfo('tok-123');

    expect($info['unique_id'])->toBe($expectedUnique)
        ->and($info['phone'])->toBeNull()
        ->and($info['name'])->toBeNull();
})->with([
    'wid without a colon' => [['wid' => 'not-a-wid', 'unique' => 'u1'], 'u1'],
    'wid without digits' => [['wid' => 'abc:1@s.whatsapp.net', 'unique' => 'u2'], 'u2'],
    'no wid and no unique' => [['something' => 'else'], null],
]);

it('throws when fetching account info gets an HTTP error', function () {
    Http::fake([WA_GW_BASE.'/get/wa.info*' => Http::response('nope', 500)]);
    Log::spy();

    expect(fn () => $this->gateway->getAccountInfo('tok-123'))
        ->toThrow(Exception::class, 'Failed to get account info: nope');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'getAccountInfo failed'));
});

/* ------------------------------------------------------------------ */
/* getConnectedAccount */
/* ------------------------------------------------------------------ */

it('returns the first connected account and skips disconnected ones', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response([
            'status' => 200,
            'data' => [
                ['unique' => 'u-off', 'phone' => '6281000000001', 'name' => 'Offline', 'status' => 'disconnected'],
                ['unique' => 'u-no-status', 'phone' => '6281000000002'],
                ['unique' => 'u-on', 'phone' => '6281000000003', 'name' => 'Online One', 'status' => 'connected'],
                ['unique' => 'u-on-2', 'phone' => '6281000000004', 'name' => 'Online Two', 'status' => 'connected'],
            ],
        ]),
    ]);

    $account = $this->gateway->getConnectedAccount('secret-1234567');

    expect($account)->toBe([
        'unique_id' => 'u-on',
        'phone' => '6281000000003',
        'name' => 'Online One',
        'status' => 'connected',
    ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['secret' => 'secret-1234567', 'limit' => '10', 'page' => '1'];
    });
});

it('uses the phone number as the account name when the gateway sends no name', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response([
            'status' => 200,
            'data' => [['unique' => 'u-on', 'phone' => '6281000000003', 'status' => 'connected']],
        ]),
    ]);

    expect($this->gateway->getConnectedAccount('secret-1234567')['name'])->toBe('6281000000003');
});

it('returns null fields for a connected account that lacks details', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response([
            'status' => 200,
            'data' => [['status' => 'connected']],
        ]),
    ]);

    expect($this->gateway->getConnectedAccount('secret-1234567'))->toBe([
        'unique_id' => null,
        'phone' => null,
        'name' => null,
        'status' => 'connected',
    ]);
});

it('returns null when no account is connected', function (array $body) {
    Http::fake([WA_GW_BASE.'/get/wa.accounts*' => Http::response($body)]);

    expect($this->gateway->getConnectedAccount('secret-1234567'))->toBeNull();
})->with([
    'only disconnected accounts' => [['status' => 200, 'data' => [['unique' => 'a', 'status' => 'disconnected']]]],
    'empty list' => [['status' => 200, 'data' => []]],
    'data key missing' => [['status' => 200]],
    'data is not a list' => [['status' => 200, 'data' => 'oops']],
]);

it('throws when listing connected accounts gets an HTTP error', function () {
    Http::fake([WA_GW_BASE.'/get/wa.accounts*' => Http::response('bad gateway', 502)]);

    expect(fn () => $this->gateway->getConnectedAccount('secret-1234567'))
        ->toThrow(Exception::class, 'Failed to get accounts: bad gateway');
});

it('throws the gateway message when listing connected accounts returns a non-200 status', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response(['status' => 401, 'message' => 'Secret rejected']),
    ]);

    expect(fn () => $this->gateway->getConnectedAccount('secret-1234567'))
        ->toThrow(Exception::class, 'Secret rejected');
});

it('uses a default message when listing connected accounts fails without a message', function () {
    Http::fake([WA_GW_BASE.'/get/wa.accounts*' => Http::response([])]);

    expect(fn () => $this->gateway->getConnectedAccount('secret-1234567'))
        ->toThrow(Exception::class, 'Failed to get accounts');
});

/* ------------------------------------------------------------------ */
/* relinkAccount */
/* ------------------------------------------------------------------ */

it('relinks an account and extracts the token', function () {
    Http::fake([
        WA_GW_BASE.'/create/wa.relink*' => Http::response(waGwQrPayload('https://example.com/info?token=relink-tok')),
    ]);

    $result = $this->gateway->relinkAccount('secret-1234567', 'uniq-abc');

    expect($result)->toBe([
        'qr_string' => 'qr-string',
        'qr_image_url' => 'https://example.com/qr.png',
        'token' => 'relink-tok',
    ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['secret' => 'secret-1234567', 'unique' => 'uniq-abc'];
    });
});

it('sends the optional server id when relinking', function () {
    Http::fake([WA_GW_BASE.'/create/wa.relink*' => Http::response(waGwQrPayload())]);

    $this->gateway->relinkAccount('secret-1234567', 'uniq-abc', 3);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['sid'] === '3' && $query['unique'] === 'uniq-abc';
    });
});

it('returns a null token when the relink response has no info link', function () {
    Http::fake([
        WA_GW_BASE.'/create/wa.relink*' => Http::response([
            'status' => 200,
            'data' => ['qrstring' => 'qr-string', 'qrimagelink' => 'https://example.com/qr.png'],
        ]),
    ]);

    $result = $this->gateway->relinkAccount('secret-1234567', 'uniq-abc');

    expect($result['token'])->toBeNull()
        ->and($result['qr_string'])->toBe('qr-string');
});

it('returns a null token when the relink info link has no token parameter', function () {
    Http::fake([
        WA_GW_BASE.'/create/wa.relink*' => Http::response(waGwQrPayload('https://example.com/info?x=1')),
    ]);

    expect($this->gateway->relinkAccount('secret-1234567', 'uniq-abc')['token'])->toBeNull();
});

it('throws when relinking gets an HTTP error', function () {
    Http::fake([WA_GW_BASE.'/create/wa.relink*' => Http::response('server down', 503)]);

    expect(fn () => $this->gateway->relinkAccount('secret-1234567', 'uniq-abc'))
        ->toThrow(Exception::class, 'Failed to relink account: server down');
});

it('throws the gateway message when relinking returns a non-200 status', function () {
    Http::fake([
        WA_GW_BASE.'/create/wa.relink*' => Http::response(['status' => 404, 'message' => 'Account unknown']),
    ]);

    expect(fn () => $this->gateway->relinkAccount('secret-1234567', 'uniq-abc'))
        ->toThrow(Exception::class, 'Account unknown');
});

it('uses a default message when relinking fails without a message', function () {
    Http::fake([WA_GW_BASE.'/create/wa.relink*' => Http::response(['status' => 500])]);

    expect(fn () => $this->gateway->relinkAccount('secret-1234567', 'uniq-abc'))
        ->toThrow(Exception::class, 'Failed to relink account');
});

/* ------------------------------------------------------------------ */
/* sendMessage */
/* ------------------------------------------------------------------ */

it('sends a multipart message with a normalised recipient number', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => Http::response(['status' => 200])]);

    $sent = $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '0812-3456 7890', 'Hello team');

    expect($sent)->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === WA_GW_BASE.'/send/whatsapp'
            && $request->isMultipart()
            && waGwMultipartFields($request) === [
                'secret' => 'secret-1234567',
                'account' => 'uniq-abc',
                'recipient' => '6281234567890',
                'type' => 'text',
                'message' => 'Hello team',
                'priority' => '2',
            ];
    });
});

it('sends the message as plain text exactly as given, without HTML escaping', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => Http::response(['status' => 200])]);

    $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', "R&D \"team\" <ok> it's 5 > 3");

    Http::assertSent(function (Request $request) {
        $fields = waGwMultipartFields($request);

        return $fields['type'] === 'text'
            && $fields['message'] === "R&D \"team\" <ok> it's 5 > 3";
    });
});

it('throws when sending a message gets an HTTP error', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => Http::response('rate limited', 429)]);

    expect(fn () => $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', 'Hi'))
        ->toThrow(Exception::class, 'Failed to send message: rate limited');
});

it('throws the gateway message when sending returns a non-200 status', function () {
    Http::fake([
        WA_GW_BASE.'/send/whatsapp' => Http::response(['status' => 400, 'message' => 'Account offline']),
    ]);

    expect(fn () => $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', 'Hi'))
        ->toThrow(Exception::class, 'Account offline');
});

it('uses a default message when sending fails without a message', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => Http::response(['status' => 500])]);

    expect(fn () => $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', 'Hi'))
        ->toThrow(Exception::class, 'Failed to send message');
});

it('treats a response without a status field as a failed send', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => Http::response(['ok' => true])]);

    expect(fn () => $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', 'Hi'))
        ->toThrow(Exception::class, 'Failed to send message');
});

it('logs the recipient and message length but never the message body when sending fails', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => Http::response('boom', 500)]);
    Log::spy();

    expect(fn () => $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', 'Secret recap text'))
        ->toThrow(Exception::class);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return str_contains($message, 'sendMessage failed')
                && $context === ['recipient' => '081234567890', 'message_length' => strlen('Secret recap text')];
        });
});

it('rethrows connection failures when sending a message', function () {
    Http::fake([WA_GW_BASE.'/send/whatsapp' => fn () => throw new ConnectionException('Connection refused')]);

    expect(fn () => $this->gateway->sendMessage('secret-1234567', 'uniq-abc', '081234567890', 'Hi'))
        ->toThrow(ConnectionException::class, 'Connection refused');
});

/* ------------------------------------------------------------------ */
/* getAccountByUniqueId */
/* ------------------------------------------------------------------ */

it('finds an account by its unique id regardless of its status', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response([
            'status' => 200,
            'data' => [
                ['unique' => 'other', 'phone' => '6281000000001', 'status' => 'connected'],
                ['unique' => 'wanted', 'phone' => '6281000000002', 'name' => 'Wanted', 'status' => 'disconnected'],
            ],
        ]),
    ]);

    $account = $this->gateway->getAccountByUniqueId('secret-1234567', 'wanted');

    expect($account)->toBe([
        'unique_id' => 'wanted',
        'phone' => '6281000000002',
        'name' => 'Wanted',
        'status' => 'disconnected',
    ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['secret' => 'secret-1234567', 'limit' => '50', 'page' => '1'];
    });
});

it('falls back to the phone as name and null status when the account omits them', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response([
            'status' => 200,
            'data' => [['unique' => 'wanted', 'phone' => '6281000000002']],
        ]),
    ]);

    expect($this->gateway->getAccountByUniqueId('secret-1234567', 'wanted'))->toBe([
        'unique_id' => 'wanted',
        'phone' => '6281000000002',
        'name' => '6281000000002',
        'status' => null,
    ]);
});

it('returns null when no account matches the unique id', function (array $body) {
    Http::fake([WA_GW_BASE.'/get/wa.accounts*' => Http::response($body)]);

    expect($this->gateway->getAccountByUniqueId('secret-1234567', 'wanted'))->toBeNull();
})->with([
    'different account only' => [['status' => 200, 'data' => [['unique' => 'someone-else', 'status' => 'connected']]]],
    'account without unique key' => [['status' => 200, 'data' => [['phone' => '6281000000002']]]],
    'empty list' => [['status' => 200, 'data' => []]],
    'data key missing' => [['status' => 200]],
    'data is not a list' => [['status' => 200, 'data' => 'oops']],
]);

it('matches the unique id strictly and case sensitively', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response([
            'status' => 200,
            'data' => [['unique' => 'AbCdEf', 'phone' => '6281000000002', 'status' => 'connected']],
        ]),
    ]);

    expect($this->gateway->getAccountByUniqueId('secret-1234567', 'abcdef'))->toBeNull();
});

it('throws when looking up an account gets an HTTP error', function () {
    Http::fake([WA_GW_BASE.'/get/wa.accounts*' => Http::response('gateway timeout', 504)]);
    Log::spy();

    expect(fn () => $this->gateway->getAccountByUniqueId('secret-1234567', 'wanted'))
        ->toThrow(Exception::class, 'Failed to get accounts: gateway timeout');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'getAccountByUniqueId failed'));
});

it('throws the gateway message when looking up an account returns a non-200 status', function () {
    Http::fake([
        WA_GW_BASE.'/get/wa.accounts*' => Http::response(['status' => 401, 'message' => 'Invalid secret']),
    ]);

    expect(fn () => $this->gateway->getAccountByUniqueId('bad-secret-1234', 'wanted'))
        ->toThrow(Exception::class, 'Invalid secret');
});

it('uses a default message when looking up an account fails without a message', function () {
    Http::fake([WA_GW_BASE.'/get/wa.accounts*' => Http::response(['status' => 500])]);

    expect(fn () => $this->gateway->getAccountByUniqueId('secret-1234567', 'wanted'))
        ->toThrow(Exception::class, 'Failed to get accounts');
});

/* ------------------------------------------------------------------ */
/* Phone number helpers */
/* ------------------------------------------------------------------ */

it('validates Indonesian phone numbers', function (string $phone, bool $valid) {
    expect($this->gateway->validatePhoneNumber($phone))->toBe($valid);
})->with([
    '08 prefix, 10 digits' => ['0812345678', true],
    '08 prefix, 12 digits' => ['081234567890', true],
    '628 prefix' => ['6281234567890', true],
    '08 prefix, longest allowed' => ['08123456789012', true],
    'too short' => ['08123', false],
    'too long' => ['0812345678901234', false],
    'wrong prefix' => ['07123456789', false],
    'plus sign not accepted' => ['+6281234567890', false],
    'contains letters' => ['0812abc78901', false],
    'contains separators' => ['0812-3456-7890', false],
    'empty string' => ['', false],
]);

it('formats phone numbers to the 628 international format', function (string $input, string $expected) {
    expect($this->gateway->formatPhoneNumber($input))->toBe($expected);
})->with([
    'local 08 format' => ['081234567890', '6281234567890'],
    'already international' => ['6281234567890', '6281234567890'],
    'dashes and spaces stripped' => ['0812-3456 7890', '6281234567890'],
    'plus and parentheses stripped' => ['+62 (812) 3456-7890', '6281234567890'],
    'bare number without country code' => ['81234567890', '6281234567890'],
    'country code 62 without the 8 gets the 8 inserted (documented behaviour)' => ['621234567', '6281234567'],
    'only separators' => ['--  ()', '62'],
]);
