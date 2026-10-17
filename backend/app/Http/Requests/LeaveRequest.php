<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|min:10|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'Tanggal mulai harus diisi.',
            'start_date.after_or_equal' => 'Tanggal mulai harus hari ini atau tanggal yang akan datang.',
            'end_date.required' => 'Tanggal selesai harus diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.',
            'reason.required' => 'Alasan cuti harus diisi.',
            'reason.min' => 'Alasan cuti minimal 10 karakter.',
            'reason.max' => 'Alasan cuti maksimal 500 karakter.',
        ];
    }
}
