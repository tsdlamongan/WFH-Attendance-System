<?php

namespace App\Http\Requests;

use App\Models\Overtime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveOvertimeRequest extends FormRequest
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
            'approved_minutes' => 'nullable|integer|min:'.Overtime::MIN_MINUTES.'|max:'.Overtime::MAX_MINUTES,
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'approved_minutes.integer' => 'Durasi yang disetujui harus berupa angka (menit).',
            'approved_minutes.min' => 'Durasi yang disetujui minimal '.Overtime::MIN_MINUTES.' menit.',
            'approved_minutes.max' => 'Durasi yang disetujui maksimal '.Overtime::MAX_MINUTES.' menit.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
