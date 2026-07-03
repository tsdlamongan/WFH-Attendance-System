<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
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
            'attendance_id' => 'required|exists:attendances,id',
            'tasks' => 'array', // Allow empty array for edge cases
            'tasks.*.id' => 'required|exists:tasks,id',
            'tasks.*.is_completed' => 'required|boolean',
            'tasks.*.blocker_reason' => 'required_if:tasks.*.is_completed,false|string|max:500|nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'attendance_id.required' => 'ID absensi wajib diisi.',
            'attendance_id.exists' => 'Data absensi tidak ditemukan.',
            'tasks.required' => 'Tugas wajib diisi.',
            'tasks.*.id.required' => 'ID tugas wajib diisi.',
            'tasks.*.id.exists' => 'Tugas tidak ditemukan.',
            'tasks.*.is_completed.required' => 'Status penyelesaian tugas wajib diisi.',
            'tasks.*.is_completed.boolean' => 'Status penyelesaian tugas harus bernilai benar atau salah.',
            'tasks.*.blocker_reason.required_if' => 'Alasan kendala wajib diisi jika tugas belum selesai.',
            'tasks.*.blocker_reason.max' => 'Alasan kendala maksimal 500 karakter.',
        ];
    }
}
