<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HolidayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && (auth()->user()->isManager() || auth()->user()->isSuperAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $holidayId = $this->route('id');
        $teamId = auth()->user()->team_id;

        $uniqueRule = Rule::unique('holidays', 'date')
            ->where(fn ($query) => $query->where('team_id', $teamId));

        if ($holidayId !== null) {
            $uniqueRule->ignore($holidayId);
        }

        return [
            'date' => ['required', 'date', $uniqueRule],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Tanggal wajib diisi.',
            'date.date' => 'Tanggal tidak valid.',
            'date.unique' => 'Tanggal libur ini sudah terdaftar untuk tim Anda.',
            'name.required' => 'Nama libur wajib diisi.',
            'name.max' => 'Nama libur maksimal 255 karakter.',
            'description.max' => 'Deskripsi maksimal 500 karakter.',
        ];
    }
}
