<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TaskRequest extends FormRequest
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
            'tasks' => 'required|array|min:1',
            'tasks.*.title' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'attendance_id.required' => 'ID absensi wajib diisi.',
            'attendance_id.exists' => 'Data absensi tidak ditemukan.',
            'tasks.required' => 'Minimal satu tugas wajib diisi.',
            'tasks.min' => 'Minimal satu tugas wajib diisi.',
            'tasks.*.title.required' => 'Judul tugas wajib diisi.',
            'tasks.*.title.max' => 'Judul tugas maksimal 255 karakter.',
        ];
    }
}
