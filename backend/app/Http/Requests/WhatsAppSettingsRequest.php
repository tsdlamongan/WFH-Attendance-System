<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only managers and super admin can update WhatsApp settings
        return auth()->check() && (auth()->user()->isManager() || auth()->user()->isSuperAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'whatsapp_recipient_phone' => [
                'required',
                'string',
                'regex:/^(08|628)\d{8,12}$/',
            ],
            'whatsapp_recap_time' => 'required|date_format:H:i',
            'whatsapp_recap_enabled' => 'required|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'whatsapp_recipient_phone.required' => 'Nomor penerima harus diisi',
            'whatsapp_recipient_phone.string' => 'Nomor penerima harus berupa teks',
            'whatsapp_recipient_phone.regex' => 'Nomor penerima harus dalam format Indonesia (08xxx atau 628xxx)',
            'whatsapp_recap_time.required' => 'Waktu pengiriman harus diisi',
            'whatsapp_recap_time.date_format' => 'Waktu pengiriman harus dalam format HH:MM',
            'whatsapp_recap_enabled.required' => 'Status pengiriman harus diisi',
            'whatsapp_recap_enabled.boolean' => 'Status pengiriman harus berupa true/false',
        ];
    }
}
