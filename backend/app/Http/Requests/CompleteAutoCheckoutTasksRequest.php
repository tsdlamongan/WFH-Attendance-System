<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteAutoCheckoutTasksRequest extends FormRequest
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
            'task_ids' => 'required|array|min:1|max:100',
            'task_ids.*' => 'integer|distinct',
        ];
    }

    public function messages(): array
    {
        return [
            'task_ids.required' => 'Pilih minimal satu tugas.',
            'task_ids.array' => 'Daftar tugas tidak valid.',
            'task_ids.min' => 'Pilih minimal satu tugas.',
            'task_ids.max' => 'Maksimal :max tugas sekali simpan.',
            'task_ids.*.integer' => 'ID tugas tidak valid.',
            'task_ids.*.distinct' => 'Tugas yang dipilih tidak boleh dobel.',
        ];
    }
}
