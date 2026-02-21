<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && (auth()->user()->isManager() || auth()->user()->isSuperAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id');
        
        // Build unique rule: exclude current user ID only when updating
        $emailRule = 'required|email|unique:users,email';
        if ($userId !== null) {
            $emailRule .= ',' . $userId;
        }
        
        // Password rules with complexity requirements
        $passwordRules = [
            $this->isMethod('post') ? 'required' : 'sometimes',
            'string',
            'min:8',
            'max:255',
            'confirmed',
            'regex:/[a-z]/',           // at least one lowercase
            'regex:/[A-Z]/',           // at least one uppercase
            'regex:/[0-9]/',           // at least one digit
            'regex:/[@$!%*#?&]/',      // at least one special char
        ];
        
        return [
            'name' => 'required|string|max:255|regex:/^[a-zA-Z\s\-\'.]+$/u',
            'email' => $emailRule,
            'password' => $passwordRules,
            'role' => 'required|in:manager,employee',
            'leave_quota_days' => 'sometimes|integer|min:0|max:365',
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
            'name.required' => 'Nama wajib diisi',
            'name.regex' => 'Nama hanya boleh mengandung huruf, spasi, tanda hubung, dan apostrof',
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid',
            'email.unique' => 'Email sudah terdaftar',
            'password.required' => 'Password wajib diisi',
            'password.min' => 'Password minimal 8 karakter',
            'password.max' => 'Password maksimal 255 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
            'password.regex' => 'Password harus mengandung minimal 1 huruf kecil, 1 huruf besar, 1 angka, dan 1 karakter spesial (@$!%*#?&)',
            'role.required' => 'Role wajib dipilih',
            'role.in' => 'Role harus manager atau employee',
        ];
    }
}
