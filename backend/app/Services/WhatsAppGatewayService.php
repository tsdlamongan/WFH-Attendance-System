<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppGatewayService
{
    private const BASE_URL = 'https://whatsapp.perekonomian.id/api';
    private const TIMEOUT = 30;

    /**
     * Create QR link for linking WhatsApp account.
     *
     * @param  string  $apiSecret
     * @param  int|null  $serverId
     * @return array
     *
     * @throws Exception
     */
    public function createQRLink(string $apiSecret, ?int $serverId = null): array
    {
        try {
            $params = ['secret' => $apiSecret];

            if ($serverId) {
                $params['sid'] = $serverId;
            }

            $response = Http::timeout(self::TIMEOUT)
                ->get(self::BASE_URL.'/create/wa.link', $params);

            if (! $response->successful()) {
                throw new Exception('Failed to create QR link: '.$response->body());
            }

            $data = $response->json();

            if (! isset($data['status']) || $data['status'] !== 200) {
                throw new Exception($data['message'] ?? 'Failed to create QR link');
            }

            // Extract token from infolink URL
            $infolink = $data['data']['infolink'];
            parse_str(parse_url($infolink, PHP_URL_QUERY), $queryParams);
            $token = $queryParams['token'] ?? null;

            return [
                'qr_string' => $data['data']['qrstring'],
                'qr_image_url' => $data['data']['qrimagelink'],
                'token' => $token,
            ];
        } catch (Exception $e) {
            Log::error('WhatsApp Gateway createQRLink failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Get WhatsApp account information after QR scan.
     *
     * @param  string  $token
     * @return array|null Returns account info if linked, null if still pending
     *
     * @throws Exception
     */
    public function getAccountInfo(string $token): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get(self::BASE_URL.'/get/wa.info', ['token' => $token]);

            if (! $response->successful()) {
                throw new Exception('Failed to get account info: '.$response->body());
            }

            $data = $response->json();

            // If still waiting for QR scan, return null
            if (! isset($data['data']) || empty($data['data'])) {
                return null;
            }

            Log::info('WhatsApp Gateway getAccountInfo response', [
                'token' => $token,
                'data' => $data['data'],
            ]);

            // Extract phone from wid (format: "6285124740348:9@s.whatsapp.net")
            $phone = null;
            if (isset($data['data']['wid'])) {
                $wid = $data['data']['wid'];
                // Extract phone number before the colon
                if (preg_match('/^(\d+):/', $wid, $matches)) {
                    $phone = $matches[1];
                }
            }

            return [
                'unique_id' => $data['data']['unique'] ?? null,
                'phone' => $phone,
                'name' => $phone, // Use phone as name since API doesn't provide name
            ];
        } catch (Exception $e) {
            Log::error('WhatsApp Gateway getAccountInfo failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all WhatsApp accounts for an API secret.
     *
     * @param  string  $apiSecret
     * @return array|null Returns first connected account info, null if no connected account
     *
     * @throws Exception
     */
    public function getConnectedAccount(string $apiSecret): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get(self::BASE_URL.'/get/wa.accounts', [
                    'secret' => $apiSecret,
                    'limit' => 10,
                    'page' => 1,
                ]);

            if (! $response->successful()) {
                throw new Exception('Failed to get accounts: '.$response->body());
            }

            $data = $response->json();

            if (! isset($data['status']) || $data['status'] !== 200) {
                throw new Exception($data['message'] ?? 'Failed to get accounts');
            }

            // Find first connected account
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $account) {
                    if (isset($account['status']) && $account['status'] === 'connected') {
                        return [
                            'unique_id' => $account['unique'] ?? null,
                            'phone' => $account['phone'] ?? null,
                            'name' => $account['name'] ?? $account['phone'] ?? null, // Use phone as name if not provided
                            'status' => $account['status'],
                        ];
                    }
                }
            }

            // No connected account found
            return null;
        } catch (Exception $e) {
            Log::error('WhatsApp Gateway getConnectedAccount failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Relink existing WhatsApp account.
     *
     * @param  string  $apiSecret
     * @param  string  $uniqueId
     * @param  int|null  $serverId
     * @return array
     *
     * @throws Exception
     */
    public function relinkAccount(string $apiSecret, string $uniqueId, ?int $serverId = null): array
    {
        try {
            $params = [
                'secret' => $apiSecret,
                'unique' => $uniqueId,
            ];

            if ($serverId) {
                $params['sid'] = $serverId;
            }

            $response = Http::timeout(self::TIMEOUT)
                ->get(self::BASE_URL.'/create/wa.relink', $params);

            if (! $response->successful()) {
                throw new Exception('Failed to relink account: '.$response->body());
            }

            $data = $response->json();

            if (! isset($data['status']) || $data['status'] !== 200) {
                throw new Exception($data['message'] ?? 'Failed to relink account');
            }

            Log::info('WhatsApp Gateway relinkAccount response', [
                'unique_id' => $uniqueId,
                'data' => $data['data'],
            ]);

            // Extract token from infolink URL (same as createQRLink)
            $token = null;
            if (isset($data['data']['infolink'])) {
                $infolink = $data['data']['infolink'];
                parse_str(parse_url($infolink, PHP_URL_QUERY), $queryParams);
                $token = $queryParams['token'] ?? null;
            }

            return [
                'qr_string' => $data['data']['qrstring'],
                'qr_image_url' => $data['data']['qrimagelink'],
                'token' => $token,
            ];
        } catch (Exception $e) {
            Log::error('WhatsApp Gateway relinkAccount failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Send WhatsApp message.
     *
     * @param  string  $apiSecret
     * @param  string  $accountUniqueId
     * @param  string  $recipient
     * @param  string  $message
     * @return bool
     *
     * @throws Exception
     */
    public function sendMessage(string $apiSecret, string $accountUniqueId, string $recipient, string $message): bool
    {
        try {
            $formattedPhone = $this->formatPhoneNumber($recipient);

            $response = Http::timeout(self::TIMEOUT)
                ->asMultipart()
                ->post(self::BASE_URL.'/send/whatsapp', [
                    ['name' => 'secret', 'contents' => $apiSecret],
                    ['name' => 'account', 'contents' => $accountUniqueId],
                    ['name' => 'recipient', 'contents' => $formattedPhone],
                    ['name' => 'type', 'contents' => 'text'],
                    ['name' => 'message', 'contents' => $message],
                    ['name' => 'priority', 'contents' => '2'], // Normal priority
                ]);

            if (! $response->successful()) {
                throw new Exception('Failed to send message: '.$response->body());
            }

            $data = $response->json();

            if (! isset($data['status']) || $data['status'] !== 200) {
                throw new Exception($data['message'] ?? 'Failed to send message');
            }

            return true;
        } catch (Exception $e) {
            Log::error('WhatsApp Gateway sendMessage failed: '.$e->getMessage(), [
                'recipient' => $recipient,
                'message_length' => strlen($message),
            ]);
            throw $e;
        }
    }

    /**
     * Validate Indonesian phone number format.
     *
     * @param  string  $phone
     * @return bool
     */
    public function validatePhoneNumber(string $phone): bool
    {
        // Accept 08xxx or 628xxx formats
        return preg_match('/^(08|628)\d{8,12}$/', $phone) === 1;
    }

    /**
     * Format phone number to 628xxx format.
     *
     * @param  string  $phone
     * @return string
     */
    public function formatPhoneNumber(string $phone): string
    {
        // Remove spaces, dashes, and other non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert 08xxx to 628xxx
        if (substr($phone, 0, 2) === '08') {
            return '62'.substr($phone, 1);
        }

        // Already in 628xxx format
        if (substr($phone, 0, 3) === '628') {
            return $phone;
        }

        // If starts with 62 but not 628, add 8
        if (substr($phone, 0, 2) === '62') {
            return '628'.substr($phone, 2);
        }

        // Default: assume it's without country code, add 62
        return '62'.$phone;
    }
}
