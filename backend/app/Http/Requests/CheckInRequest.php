<?php

namespace App\Http\Requests;

use App\Models\Overtime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
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
            'tasks' => 'required|array|min:1|max:20',
            'tasks.*.title' => 'required|string|max:255',
            // Needed only when the check-in is an overtime session; the service decides.
            'overtime' => 'nullable|array',
            'overtime.reason' => 'required_with:overtime|string|min:5|max:500',
            'overtime.planned_minutes' => 'required_with:overtime|integer|min:'.Overtime::MIN_MINUTES.'|max:'.Overtime::MAX_MINUTES,
        ];
    }

    public function messages(): array
    {
        return [
            'tasks.required' => 'Minimal satu tugas wajib diisi.',
            'tasks.min' => 'Minimal satu tugas wajib diisi.',
            'tasks.max' => 'Maksimal 20 tugas.',
            'tasks.*.title.required' => 'Judul tugas wajib diisi.',
            'tasks.*.title.max' => 'Judul tugas maksimal 255 karakter.',
            'overtime.reason.required_with' => 'Alasan lembur harus diisi.',
            'overtime.reason.min' => 'Alasan lembur minimal 5 karakter.',
            'overtime.reason.max' => 'Alasan lembur maksimal 500 karakter.',
            'overtime.planned_minutes.required_with' => 'Durasi lembur harus diisi.',
            'overtime.planned_minutes.integer' => 'Durasi lembur harus berupa angka (menit).',
            'overtime.planned_minutes.min' => 'Durasi lembur minimal '.Overtime::MIN_MINUTES.' menit.',
            'overtime.planned_minutes.max' => 'Durasi lembur maksimal '.Overtime::MAX_MINUTES.' menit.',
        ];
    }
}
