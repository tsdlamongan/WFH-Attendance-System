<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppLinkExistingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only managers and super admin can link WhatsApp
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
            'api_secret' => 'required|string|min:10',
            'unique_id' => 'required|string|min:20',
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
            'api_secret.required' => 'API Secret harus diisi',
            'api_secret.string' => 'API Secret harus berupa teks',
            'api_secret.min' => 'API Secret minimal 10 karakter',
            'unique_id.required' => 'Unique ID harus diisi',
            'unique_id.string' => 'Unique ID harus berupa teks',
            'unique_id.min' => 'Unique ID minimal 20 karakter',
        ];
    }
}
