<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'connected' => $this->whatsapp_connected,
            'connected_at' => $this->whatsapp_connected_at?->toIso8601String(),
            'account_unique_id' => $this->whatsapp_account_unique_id,
            'account_phone' => $this->whatsapp_account_phone,
            'account_name' => $this->whatsapp_account_name,
            'recipient_phone' => $this->whatsapp_recipient_phone,
            'recap_time' => $this->whatsapp_recap_time ? substr($this->whatsapp_recap_time, 0, 5) : null, // Format to HH:MM
            'recap_enabled' => $this->whatsapp_recap_enabled,
            'last_sent_at' => $this->whatsapp_last_sent_at?->toIso8601String(),
            'last_error' => $this->whatsapp_last_error,
            'can_send_recap' => $this->canSendWhatsappRecap(),
        ];
    }
}
