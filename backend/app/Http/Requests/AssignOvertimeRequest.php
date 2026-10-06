<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Overtime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOvertimeRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only employees of the manager's own team can be picked.
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('team_id', auth()->user()?->team_id)
                    ->where('role', UserRole::EMPLOYEE->value),
            ],
            'reason' => 'required|string|min:5|max:500',
            'planned_minutes' => 'required|integer|min:'.Overtime::MIN_MINUTES.'|max:'.Overtime::MAX_MINUTES,
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan harus dipilih.',
            'user_id.exists' => 'Karyawan tidak ditemukan di tim Anda.',
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
