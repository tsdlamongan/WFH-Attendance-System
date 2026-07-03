<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceEditRequest extends FormRequest
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
        return [
            'date' => 'required|date',
            'check_in' => 'required|date',
            'check_out' => 'nullable|date|after:check_in',
            'reason' => 'required|string|min:10',
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Tanggal wajib diisi.',
            'date.date' => 'Tanggal tidak valid.',
            'check_in.required' => 'Waktu check-in wajib diisi.',
            'check_out.after' => 'Waktu check-out harus setelah check-in.',
            'reason.required' => 'Alasan wajib diisi untuk audit.',
            'reason.min' => 'Alasan minimal 10 karakter.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Ensure check_in date matches the date field
            $date = $this->input('date');
            $checkIn = $this->input('check_in');

            if ($date && $checkIn) {
                $dateOnly = Carbon::parse($date)->toDateString();
                $checkInDate = Carbon::parse($checkIn)->toDateString();

                if ($dateOnly !== $checkInDate) {
                    $validator->errors()->add(
                        'check_in',
                        'Tanggal check-in harus sama dengan tanggal absensi.'
                    );
                }
            }

            // Note: check_out date is NOT validated to match attendance date
            // because employees may work overtime and check out on a different day
        });
    }
}
