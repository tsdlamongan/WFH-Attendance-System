<?php

namespace App\Http\Requests;

use App\Models\Overtime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OvertimeRequest extends FormRequest
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
            'reason' => 'required|string|min:5|max:500',
            'planned_minutes' => 'required|integer|min:'.Overtime::MIN_MINUTES.'|max:'.Overtime::MAX_MINUTES,
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan lembur harus diisi.',
            'reason.min' => 'Alasan lembur minimal 5 karakter.',
            'reason.max' => 'Alasan lembur maksimal 500 karakter.',
            'planned_minutes.required' => 'Durasi lembur harus diisi.',
            'planned_minutes.integer' => 'Durasi lembur harus berupa angka (menit).',
            'planned_minutes.min' => 'Durasi lembur minimal '.Overtime::MIN_MINUTES.' menit.',
            'planned_minutes.max' => 'Durasi lembur maksimal '.Overtime::MAX_MINUTES.' menit.',
        ];
    }
}
