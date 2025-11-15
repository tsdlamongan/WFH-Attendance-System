<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeamSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only managers and super admin can update team settings
        return auth()->check() && (auth()->user()->isManager() || auth()->user()->isSuperAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'required_work_hours' => 'sometimes|numeric|min:1|max:24',
            'default_leave_quota_days' => 'sometimes|integer|min:0|max:365',
            'max_leave_days_per_month' => 'sometimes|integer|min:0|max:31',
            'check_in_window_start' => 'sometimes|date_format:H:i',
            'check_in_window_end' => 'sometimes|date_format:H:i|after:check_in_window_start',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'Nama tim harus berupa teks',
            'name.max' => 'Nama tim maksimal 255 karakter',
            'required_work_hours.numeric' => 'Jam kerja harus berupa angka',
            'required_work_hours.min' => 'Jam kerja minimal 1 jam',
            'required_work_hours.max' => 'Jam kerja maksimal 24 jam',
            'default_leave_quota_days.integer' => 'Jatah cuti harus berupa angka',
            'default_leave_quota_days.min' => 'Jatah cuti minimal 0 hari',
            'default_leave_quota_days.max' => 'Jatah cuti maksimal 365 hari',
            'max_leave_days_per_month.integer' => 'Maksimal cuti per bulan harus berupa angka',
            'max_leave_days_per_month.min' => 'Maksimal cuti per bulan minimal 0 hari',
            'max_leave_days_per_month.max' => 'Maksimal cuti per bulan maksimal 31 hari',
            'check_in_window_start.date_format' => 'Waktu mulai check-in harus dalam format HH:MM',
            'check_in_window_end.date_format' => 'Waktu akhir check-in harus dalam format HH:MM',
            'check_in_window_end.after' => 'Waktu akhir check-in harus setelah waktu mulai',
        ];
    }
}
