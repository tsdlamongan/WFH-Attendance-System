<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = $this->user();

        return $user !== null && $user->isManager();
    }

    public function rules(): array
    {
        return [
            'year' => 'required|integer|min:2020|max:2099',
            'quota_days' => 'required|integer|min:0|max:365',
        ];
    }

    public function messages(): array
    {
        return [
            'year.required' => 'Tahun wajib diisi.',
            'year.integer' => 'Tahun harus berupa angka.',
            'year.min' => 'Tahun minimal 2020.',
            'year.max' => 'Tahun maksimal 2099.',
            'quota_days.required' => 'Jumlah jatah cuti wajib diisi.',
            'quota_days.integer' => 'Jumlah jatah cuti harus berupa angka.',
            'quota_days.min' => 'Jumlah jatah cuti minimal 0.',
            'quota_days.max' => 'Jumlah jatah cuti maksimal 365.',
        ];
    }
}
