<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectOvertimeRequest extends FormRequest
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
            'notes' => 'required|string|min:3|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Catatan penolakan harus diisi.',
            'notes.min' => 'Catatan penolakan minimal 3 karakter.',
            'notes.max' => 'Catatan penolakan maksimal 500 karakter.',
        ];
    }
}
