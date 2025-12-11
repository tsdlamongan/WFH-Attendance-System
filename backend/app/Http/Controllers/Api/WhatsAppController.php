<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsAppConfirmLinkRequest;
use App\Http\Requests\WhatsAppCreateLinkRequest;
use App\Http\Requests\WhatsAppLinkExistingRequest;
use App\Http\Requests\WhatsAppSettingsRequest;
use App\Http\Resources\WhatsAppSettingsResource;
use App\Services\ActivityLogService;
use App\Services\WhatsAppGatewayService;
use App\Services\WhatsAppRecapService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppGatewayService $whatsAppGatewayService,
        private WhatsAppRecapService $whatsAppRecapService,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Create QR link for linking WhatsApp account.
     */
    public function createLink(WhatsAppCreateLinkRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            $apiSecret = $request->input('api_secret');

            // Create QR link
            $qrData = $this->whatsAppGatewayService->createQRLink($apiSecret);

            // Save API secret and token temporarily
            $team->update([
                'whatsapp_api_secret' => $apiSecret,
                'whatsapp_token' => $qrData['token'],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'qr_string' => $qrData['qr_string'],
                    'qr_image_url' => $qrData['qr_image_url'],
                    'token' => $qrData['token'],
                ],
                'message' => 'QR Code berhasil dibuat. Scan dengan WhatsApp Anda.',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp create link failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat QR Code: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get WhatsApp account info (for polling).
     */
    public function accountInfo(Request $request): JsonResponse
    {
        try {
            $token = $request->query('token');

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token diperlukan',
                ], 400);
            }

            $accountInfo = $this->whatsAppGatewayService->getAccountInfo($token);

            if (! $accountInfo) {
                // Still waiting for QR scan
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'Menunggu scan QR Code',
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => $accountInfo,
                'message' => 'Akun WhatsApp terdeteksi',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp account info failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil informasi akun',
            ], 500);
        }
    }

    /**
     * Check for connected WhatsApp account (better polling method).
     */
    public function checkConnection(): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team || ! $team->whatsapp_api_secret) {
                return response()->json([
                    'success' => false,
                    'message' => 'API Secret belum dikonfigurasi',
                ], 400);
            }

            $accountInfo = $this->whatsAppGatewayService->getConnectedAccount($team->whatsapp_api_secret);

            if (! $accountInfo) {
                // No connected account yet
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'Belum ada akun terhubung',
                ], 200);
            }

            Log::info('WhatsApp account info received', [
                'team_id' => $team->id,
                'account_info' => $accountInfo,
            ]);

            // If account is found and not yet saved, save it
            if (! $team->whatsapp_connected || $team->whatsapp_account_unique_id !== $accountInfo['unique_id']) {
                $team->update([
                    'whatsapp_account_unique_id' => $accountInfo['unique_id'],
                    'whatsapp_account_phone' => $accountInfo['phone'],
                    'whatsapp_account_name' => $accountInfo['name'],
                    'whatsapp_connected' => true,
                    'whatsapp_connected_at' => Carbon::now(),
                    'whatsapp_token' => null, // Clear temporary token
                ]);

                Log::info('WhatsApp account saved to database', [
                    'team_id' => $team->id,
                    'unique_id' => $accountInfo['unique_id'],
                    'phone' => $accountInfo['phone'],
                    'name' => $accountInfo['name'],
                ]);

                // Log activity
                $this->activityLogService->logActivity(
                    $user,
                    ActivityType::WHATSAPP_CONNECTED,
                    "WhatsApp account connected: {$accountInfo['phone']}",
                    request()
                );
            }

            return response()->json([
                'success' => true,
                'data' => $accountInfo,
                'message' => 'Akun WhatsApp ditemukan',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp check connection failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa koneksi',
            ], 500);
        }
    }

    /**
     * Confirm WhatsApp link after successful QR scan.
     */
    public function confirmLink(WhatsAppConfirmLinkRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            $token = $request->input('token');

            // Get account info
            $accountInfo = $this->whatsAppGatewayService->getAccountInfo($token);

            if (! $accountInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun WhatsApp belum terhubung',
                ], 400);
            }

            // Update team with account info
            $team->update([
                'whatsapp_account_unique_id' => $accountInfo['unique_id'],
                'whatsapp_account_phone' => $accountInfo['phone'],
                'whatsapp_account_name' => $accountInfo['name'],
                'whatsapp_connected' => true,
                'whatsapp_connected_at' => Carbon::now(),
                'whatsapp_token' => null, // Clear temporary token
            ]);

            // Refresh team model to get updated data
            $team->refresh();

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_CONNECTED,
                "WhatsApp account connected: {$accountInfo['phone']}",
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new WhatsAppSettingsResource($team),
                'message' => 'WhatsApp berhasil terhubung',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp confirm link failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungkan WhatsApp',
            ], 500);
        }
    }

    /**
     * Link existing WhatsApp account by unique ID.
     */
    public function linkExisting(WhatsAppLinkExistingRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            $apiSecret = $request->input('api_secret');
            $uniqueId = $request->input('unique_id');

            // Get account info from WA Gateway
            $accountInfo = $this->whatsAppGatewayService->getAccountByUniqueId($apiSecret, $uniqueId);

            if (! $accountInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account tidak ditemukan di WhatsApp Gateway. Pastikan Unique ID benar.',
                ], 404);
            }

            if ($accountInfo['status'] !== 'connected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Account tidak dalam status connected. Status: '.$accountInfo['status'],
                ], 400);
            }

            // Update team with account info
            $team->update([
                'whatsapp_api_secret' => $apiSecret,
                'whatsapp_account_unique_id' => $accountInfo['unique_id'],
                'whatsapp_account_phone' => $accountInfo['phone'],
                'whatsapp_account_name' => $accountInfo['name'],
                'whatsapp_connected' => true,
                'whatsapp_connected_at' => Carbon::now(),
                'whatsapp_token' => null,
            ]);

            // Refresh team model
            $team->refresh();

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_CONNECTED,
                "WhatsApp account linked (existing): {$accountInfo['phone']}",
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new WhatsAppSettingsResource($team),
                'message' => 'WhatsApp berhasil terhubung',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp link existing failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungkan WhatsApp: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Relink WhatsApp account.
     */
    public function relink(): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            if (! $team->whatsapp_api_secret || ! $team->whatsapp_account_unique_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp belum pernah terhubung',
                ], 400);
            }

            // Create new QR link
            $qrData = $this->whatsAppGatewayService->relinkAccount(
                $team->whatsapp_api_secret,
                $team->whatsapp_account_unique_id
            );

            // Save token temporarily (for polling)
            $team->update([
                'whatsapp_connected' => false,
                'whatsapp_token' => $qrData['token'],
            ]);

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_RELINKED,
                'WhatsApp account relink initiated',
                request()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'qr_string' => $qrData['qr_string'],
                    'qr_image_url' => $qrData['qr_image_url'],
                    'token' => $qrData['token'],
                ],
                'message' => 'QR Code untuk relink berhasil dibuat',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp relink failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat QR Code relink',
            ], 500);
        }
    }

    /**
     * Disconnect WhatsApp account.
     */
    public function disconnect(): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            // Clear WhatsApp connection
            $team->update([
                'whatsapp_connected' => false,
                'whatsapp_account_unique_id' => null,
                'whatsapp_account_phone' => null,
                'whatsapp_account_name' => null,
                'whatsapp_connected_at' => null,
                'whatsapp_recap_enabled' => false,
            ]);

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_DISCONNECTED,
                'WhatsApp account disconnected',
                request()
            );

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp berhasil diputuskan',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp disconnect failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memutuskan WhatsApp',
            ], 500);
        }
    }

    /**
     * Get WhatsApp settings.
     */
    public function getSettings(): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new WhatsAppSettingsResource($team),
            ], 200);
        } catch (Exception $e) {
            Log::error('Get WhatsApp settings failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil pengaturan WhatsApp',
            ], 500);
        }
    }

    /**
     * Update WhatsApp settings.
     */
    public function updateSettings(WhatsAppSettingsRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            $team->update($request->validated());

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_SETTINGS_UPDATED,
                'WhatsApp settings updated',
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new WhatsAppSettingsResource($team),
                'message' => 'Pengaturan WhatsApp berhasil diperbarui',
            ], 200);
        } catch (Exception $e) {
            Log::error('Update WhatsApp settings failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengaturan WhatsApp',
            ], 500);
        }
    }

    /**
     * Send test WhatsApp message.
     */
    public function testSend(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            if (! $team->isWhatsappConnected() || ! $team->whatsapp_recipient_phone) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp belum dikonfigurasi dengan lengkap',
                ], 400);
            }

            $message = $request->input('message', 'Test message from WFH Attendance System');

            $this->whatsAppGatewayService->sendMessage(
                $team->whatsapp_api_secret,
                $team->whatsapp_account_unique_id,
                $team->whatsapp_recipient_phone,
                $message
            );

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_TEST_SENT,
                'Test WhatsApp message sent',
                $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Pesan test berhasil dikirim',
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp test send failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim pesan test: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview today's recap.
     */
    public function previewRecap(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            $date = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::today();

            $message = $this->whatsAppRecapService->previewRecap($team, $date);

            return response()->json([
                'success' => true,
                'data' => ['message' => $message],
            ], 200);
        } catch (Exception $e) {
            Log::error('WhatsApp preview recap failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat preview recap',
            ], 500);
        }
    }

    /**
     * Manually send recap.
     */
    public function sendRecap(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            if (! $team->canSendWhatsappRecap()) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp belum dikonfigurasi dengan lengkap',
                ], 400);
            }

            $date = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::today();

            $success = $this->whatsAppRecapService->sendRecap($team, $date);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Recap berhasil dikirim via WhatsApp',
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengirim recap: '.$team->whatsapp_last_error,
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('WhatsApp send recap failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim recap',
            ], 500);
        }
    }
}
