<?php

namespace App\Http\Requests;

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
        ];
    }
}
